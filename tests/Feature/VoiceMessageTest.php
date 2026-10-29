<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\WebhookController;
use App\Mail\OwnerNotificationMail;
use App\Models\AdminAccessLog;
use App\Models\Business;
use App\Models\Escalation;
use App\Models\LearnedResponse;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\AI\AiCostService;
use App\Services\AI\LearningService;
use App\Services\AI\VoiceMessageService;
use App\Services\Billing\UsageService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FakeWhatsAppService;
use Tests\Support\FakeWhatsAppServiceFactory;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class VoiceMessageTest extends TestCase
{
    use CreatesConversationSchema;

    private const GRAPH = 'https://graph.facebook.com/v21.0/';

    private const MEDIA_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1';

    private const CUSTOMER = '22670000001';

    private const CONTEXT = 'Pagne wax bleu : 7 500 FCFA.';

    private User $owner;

    private Business $business;

    /** Durée du faux vocal (secondes) et taille annoncée par Meta. */
    private int $audioSeconds = 12;

    private ?int $declaredSize = null;

    /** Média expiré chez Meta (plus de 30 jours). */
    private bool $mediaExpired = false;

    /** Réponse de la fausse transcription. */
    private array $transcription = ['transcript' => 'Vous avez le pagne wax bleu ?', 'language' => 'fr', 'understood' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 10:00:00');
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            'services.gemini.api_key' => 'gemini-key',
            'services.gemini.transcription_model' => 'gemini-2.5-flash',
            'services.whatsapp.token' => 'system-token',
            'services.whatsapp.api_url' => self::GRAPH,
            'services.causeo_notify.phone_number_id' => 'CAUSEO-PHONE-ID',
            'app.frontend_url' => 'https://getcauseo.com',
        ]);

        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_100000_add_whatsapp_alerts_to_users.php'))->up();
        (require base_path('database/migrations/2026_10_29_110000_add_whatsapp_alerts_consent_version.php'))->up();
        Schema::create('learned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('escalation_id')->nullable();
            $table->text('question');
            $table->text('answer');
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        $this->owner = User::forceCreate([
            'name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x',
            'notification_phone' => '+22670112233', 'whatsapp_alerts_enabled' => true, 'whatsapp_alerts_consented_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]',
            'whatsapp_phone_number_id' => 'PNID-JOYCE', 'whatsapp_verified' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);
        $this->business->forceFill(['whatsapp_token' => 'business-token'])->save();

        // Sans Mockery : le traitement du webhook est une closure sérialisée par la file « sync ».
        FakeWhatsAppService::$sent = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService(self::CONTEXT));

        Mail::fake();
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                $this->mediaExpired && str_starts_with($url, self::GRAPH.'MEDIA-') => Http::response(['error' => ['message' => 'Media not found']], 404),
                str_starts_with($url, self::GRAPH.'MEDIA-') => Http::response([
                    'url' => self::MEDIA_URL, 'mime_type' => 'audio/ogg; codecs=opus',
                    'file_size' => $this->declaredSize ?? strlen($this->ogg($this->audioSeconds)), 'id' => 'MEDIA-1',
                ]),
                $url === self::MEDIA_URL => Http::response($this->ogg($this->audioSeconds), 200, ['Content-Type' => 'audio/ogg']),
                str_contains($url, 'generativelanguage.googleapis.com') => Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => json_encode($this->transcription, JSON_UNESCAPED_UNICODE)]]]]],
                    'usageMetadata' => [
                        'promptTokenCount' => 32 * $this->audioSeconds + 100,
                        'candidatesTokenCount' => 50,
                        'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 32 * $this->audioSeconds], ['modality' => 'TEXT', 'tokenCount' => 100]],
                    ],
                ]),
                str_contains($url, 'api.anthropic.com') => Http::response([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => 'Oui, le pagne wax bleu est à 7 500 FCFA.']],
                    'usage' => ['input_tokens' => 1000, 'output_tokens' => 30],
                ]),
                // Alerte WhatsApp au gérant (numéro Causeo).
                str_starts_with($url, self::GRAPH.'CAUSEO-PHONE-ID/messages') => Http::response(['messages' => [['id' => 'wamid.ALERT']]]),
                default => Http::response(['error' => ['message' => "URL inattendue : {$url}"]], 500),
            };
        });
    }

    // --- Vocal en français ---------------------------------------------------------------------

    public function test_a_french_voice_message_is_answered_like_a_text_message(): void
    {
        $tempFiles = $this->tempVoiceFiles();

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        // Téléchargement avec le token du business, transcription de l'OGG par Gemini.
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH.'MEDIA-1' && $r->hasHeader('Authorization', 'Bearer business-token'));
        Http::assertSent(fn (Request $r) => $r->url() === self::MEDIA_URL && $r->hasHeader('Authorization', 'Bearer business-token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'gemini-2.5-flash:generateContent')
            && $r['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'audio/ogg'
            && base64_decode($r['contents'][0]['parts'][0]['inline_data']['data']) === $this->ogg(12)
            && $r['generationConfig']['responseMimeType'] === 'application/json');
        // La transcription est la question posée à Claude.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && str_ends_with($r['messages'][0]['content'], 'Question du client : Vous avez le pagne wax bleu ?'));
        $this->assertSame(['Oui, le pagne wax bleu est à 7 500 FCFA.'], FakeWhatsAppService::$sent);

        $inbound = Message::where('direction', 'inbound')->sole();
        $this->assertSame('Vous avez le pagne wax bleu ?', $inbound->content);
        $this->assertSame('answered_by_ai', $inbound->status);
        $this->assertSame([
            'type' => 'audio', 'media_id' => 'MEDIA-1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true,
            'voice_status' => 'transcribed', 'transcript' => 'Vous avez le pagne wax bleu ?', 'language' => 'fr', 'duration_seconds' => 12,
            // Coût de la transcription, sur le vocal lui-même.
            'ai_usage' => ['transcription' => ['model' => 'gemini-2.5-flash', 'input_audio_tokens' => 384, 'input_text_tokens' => 100, 'output_tokens' => 50]],
        ], $inbound->metadata);

        $reply = Message::where('direction', 'outbound')->sole();
        $this->assertSame('claude-test', $reply->metadata['ai_usage']['model']);
        $this->assertArrayNotHasKey('transcription', $reply->metadata['ai_usage']);
        $this->assertSame(0, Escalation::count());
        $this->assertSame(1, $this->unitsUsed());
        $this->assertSame($tempFiles, $this->tempVoiceFiles(), 'Le fichier temporaire est supprimé.');
    }

    public function test_a_french_greeting_by_voice_still_counts_as_one_unit(): void
    {
        $this->transcription = ['transcript' => 'Bonjour', 'language' => 'fr', 'understood' => true];

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertCount(1, FakeWhatsAppService::$sent);
        $this->assertArrayNotHasKey('canned', Message::where('direction', 'outbound')->sole()->metadata);
        $this->assertSame(1, $this->unitsUsed());
    }

    // --- Vocaux transmis au gérant ----------------------------------------------------------------

    public function test_a_voice_message_in_another_language_goes_to_the_owner(): void
    {
        $this->transcription = ['transcript' => 'Do you have the blue wax?', 'language' => 'en', 'understood' => true];

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertOwnerWasAlerted('Message vocal en anglais', 'voice_other_language');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));

        $inbound = Message::where('direction', 'inbound')->sole();
        $this->assertSame('escalated', $inbound->status);
        $this->assertSame('Do you have the blue wax?', $inbound->metadata['transcript']);
        $this->assertSame('en', $inbound->metadata['language']);
        $this->assertSame('other_language', $inbound->metadata['voice_status']);
        $this->assertSame(384, $inbound->metadata['ai_usage']['transcription']['input_audio_tokens']);

        // Par défaut, le bot ne répond pas : aucune unité.
        $this->assertSame(0, $this->unitsUsed());
        $this->assertSame(0, $this->business->fresh()->monthly_message_count);
    }

    public function test_when_enabled_the_bot_sends_the_default_text_and_counts_one_unit(): void
    {
        $this->business->update(['voice_fallback_reply' => true]);
        $this->transcription = ['transcript' => 'Do you have the blue wax?', 'language' => 'en', 'understood' => true];

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertSame([WebhookController::VOICE_FALLBACK_MESSAGE], FakeWhatsAppService::$sent);
        $reply = Message::where('direction', 'outbound')->sole();
        $this->assertSame(WebhookController::VOICE_FALLBACK_MESSAGE, $reply->content);
        $this->assertSame('other_language', $reply->metadata['voice_fallback']);
        $this->assertSame(1, Escalation::count());
        $this->assertSame(1, $this->unitsUsed());
    }

    public function test_when_enabled_the_bot_sends_the_owner_message(): void
    {
        $this->business->update(['voice_fallback_reply' => true, 'voice_fallback_message' => '  Merci ! Joyce vous rappelle dans la journée.  ']);
        $this->audioSeconds = 200;

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertSame(['Merci ! Joyce vous rappelle dans la journée.'], FakeWhatsAppService::$sent);
        $this->assertSame(1, $this->unitsUsed());
    }

    public function test_the_default_text_ends_with_a_single_period(): void
    {
        $this->assertSame(
            'Merci pour votre message vocal ! Je le transmets tout de suite au responsable, qui vous répondra rapidement.',
            WebhookController::VOICE_FALLBACK_MESSAGE,
        );
        $this->assertDoesNotMatchRegularExpression('/\.\.$/', WebhookController::VOICE_FALLBACK_MESSAGE);
    }

    public function test_owner_reads_and_updates_the_voice_fallback_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $this->assertFalse($this->business->fresh()->voice_fallback_reply);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['voice_fallback_reply' => true, 'voice_fallback_message' => 'On vous rappelle très vite.'])
            ->assertOk()
            ->assertJsonPath('business.voice_fallback_reply', true)
            ->assertJsonPath('business.voice_fallback_message', 'On vous rappelle très vite.');

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['voice_fallback_message' => ''])
            ->assertOk()
            ->assertJsonPath('business.voice_fallback_reply', true)
            ->assertJsonPath('business.voice_fallback_message', null);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['voice_fallback_reply' => 'peut-être', 'voice_fallback_message' => str_repeat('x', 301)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voice_fallback_reply', 'voice_fallback_message'])
            ->assertJsonPath('errors.voice_fallback_message.0', 'Le message de réponse aux vocaux, documents et vidéos ne doit pas dépasser 300 caractères.');

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['voice_fallback_message' => str_repeat('x', 300)])->assertOk();
    }

    public function test_an_unintelligible_voice_message_goes_to_the_owner(): void
    {
        $this->transcription = ['transcript' => '', 'language' => 'und', 'understood' => false];

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertOwnerWasAlerted('Message vocal incompréhensible', 'voice_unintelligible');
        $inbound = Message::where('direction', 'inbound')->sole();
        $this->assertSame(WebhookController::VOICE_PLACEHOLDER, $inbound->content);
        $this->assertSame('unintelligible', $inbound->metadata['voice_status']);
        $this->assertArrayNotHasKey('transcript', $inbound->metadata);
    }

    public function test_a_voice_message_longer_than_3_minutes_is_not_transcribed(): void
    {
        $this->audioSeconds = 200;

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertOwnerWasAlerted('Message vocal trop long (3 min 20 s)', 'voice_too_long');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertSame(200, Message::where('direction', 'inbound')->sole()->metadata['duration_seconds']);
        $this->assertArrayNotHasKey('ai_usage', Message::where('direction', 'inbound')->sole()->metadata);
    }

    public function test_a_voice_file_over_10_mb_is_not_downloaded(): void
    {
        $this->declaredSize = 11 * 1024 * 1024;

        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();

        $this->assertOwnerWasAlerted('Message vocal trop long', 'voice_too_long');
        Http::assertNotSent(fn (Request $r) => $r->url() === self::MEDIA_URL);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_the_owner_answer_closes_the_voice_escalation_without_learning(): void
    {
        $this->transcription = ['transcript' => 'Do you deliver?', 'language' => 'en', 'understood' => true];
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        $conversation = Escalation::sole()->conversation;

        app(LearningService::class)->answerPendingEscalation($conversation, 'Yes, we deliver in Ouaga.');

        $escalation = Escalation::sole();
        $this->assertSame('answered', $escalation->status);
        $this->assertSame('Yes, we deliver in Ouaga.', $escalation->human_response);
        $this->assertSame(0, LearnedResponse::count());
    }

    // --- Échos du gérant -------------------------------------------------------------------------------

    public function test_an_audio_echo_from_the_owner_is_kept_without_transcription(): void
    {
        $this->transcription = ['transcript' => 'Do you deliver?', 'language' => 'en', 'understood' => true];
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        Http::fake();

        $this->postJson('/api/webhook/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'smb_message_echoes', 'value' => [
                'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                'message_echoes' => [['from' => '22660000000', 'to' => self::CUSTOMER, 'id' => 'wamid.ECHO', 'type' => 'audio', 'audio' => ['id' => 'MEDIA-ECHO', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]],
            ]]],
        ]]])->assertOk();

        $echo = Message::where('whatsapp_message_id', 'wamid.ECHO')->sole();
        $this->assertSame('human', $echo->sender_type);
        $this->assertSame(WebhookController::VOICE_PLACEHOLDER, $echo->content);
        $this->assertSame(['type' => 'audio', 'media_id' => 'MEDIA-ECHO', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true], $echo->metadata);
        $this->assertSame('answered', Escalation::sole()->status);
        $this->assertSame(0, LearnedResponse::count());
        Http::assertNothingSent();
    }

    // --- Réécoute depuis le dashboard ------------------------------------------------------------------

    public function test_the_owner_can_listen_to_a_voice_message(): void
    {
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        $inbound = Message::where('direction', 'inbound')->sole();

        Sanctum::actingAs($this->owner);
        $response = $this->get("/api/v1/messages/{$inbound->id}/audio")->assertOk();
        $this->assertSame($this->ogg(12), $response->getContent());
        $this->assertStringStartsWith('audio/ogg', $response->headers->get('Content-Type'));

        $text = Message::where('direction', 'outbound')->sole();
        $this->get("/api/v1/messages/{$text->id}/audio")->assertNotFound();

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->get("/api/v1/messages/{$inbound->id}/audio")->assertForbidden();
    }

    public function test_an_admin_can_listen_and_each_listening_is_logged(): void
    {
        (require base_path('database/migrations/2026_10_23_110000_create_admin_access_logs_table.php'))->up();
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        $inbound = Message::where('direction', 'inbound')->sole();

        // Le gérant écoute son propre vocal : rien à journaliser.
        Sanctum::actingAs($this->owner);
        $this->get("/api/v1/messages/{$inbound->id}/audio")->assertOk();
        $this->assertSame(0, AdminAccessLog::count());

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        Sanctum::actingAs($admin);
        $response = $this->get("/api/v1/messages/{$inbound->id}/audio")->assertOk();
        $this->assertSame($this->ogg(12), $response->getContent());
        $this->travel(5)->minutes();
        $this->get("/api/v1/messages/{$inbound->id}/audio")->assertOk();

        $logs = AdminAccessLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(
            ['admin_id' => $admin->id, 'action' => 'voice_message_listened', 'message_id' => $inbound->id, 'business_id' => $this->business->id],
            $logs[0]->only(['admin_id', 'action', 'message_id', 'business_id']),
        );
        $this->assertSame('2026-10-23 10:00:00', $logs[0]->created_at->toDateTimeString());
        $this->assertSame('2026-10-23 10:05:00', $logs[1]->created_at->toDateTimeString());
        // Ni le son ni la transcription dans le journal.
        $this->assertSame(['id', 'admin_id', 'action', 'message_id', 'business_id', 'created_at'], array_keys($logs[0]->getAttributes()));

        // Un utilisateur ordinaire reste refusé, sans entrée.
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->get("/api/v1/messages/{$inbound->id}/audio")->assertForbidden();
        $this->assertSame(2, AdminAccessLog::count());
    }

    public function test_an_expired_voice_message_returns_410(): void
    {
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        $this->mediaExpired = true;

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/messages/'.Message::where('direction', 'inbound')->sole()->id.'/audio')
            ->assertStatus(410)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'plus disponible'));
    }

    // --- Coûts -------------------------------------------------------------------------------------------

    public function test_transcription_cost_is_added_to_ai_costs(): void
    {
        config(['services.anthropic.pricing.models.claude-test' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1]]);
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload())->assertOk();
        $this->transcription = ['transcript' => 'Do you deliver?', 'language' => 'en', 'understood' => true];
        $this->postJson('/api/webhook/whatsapp', $this->audioPayload('wamid.VOICE2'))->assertOk();

        $totals = app(AiCostService::class)->month(CarbonImmutable::now())['totals'];

        // Claude : 1 réponse (1 000 × 3 + 30 × 15) ; Gemini : 2 × (384 × 1,00 + 100 × 0,30 + 50 × 2,50).
        $this->assertSame(1, $totals['replies']);
        $this->assertSame(2, $totals['transcriptions']);
        $this->assertSame(0.0011, $totals['transcription_cost_usd']);
        $this->assertSame(round(0.00345 + 0.001078, 4), $totals['cost_usd']);
        $transcription = collect($totals['models'])->firstWhere('kind', 'transcription');
        $this->assertSame('gemini-2.5-flash', $transcription['model']);
        $this->assertSame(2, $transcription['replies']);
    }

    public function test_ogg_opus_duration_is_read_from_the_last_page(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ogg-test-');
        file_put_contents($path, $this->ogg(95));
        $this->assertSame(95, VoiceMessageService::oggOpusDuration($path));

        file_put_contents($path, 'ID3'.str_repeat("\0", 200));
        $this->assertNull(VoiceMessageService::oggOpusDuration($path));
        unlink($path);
    }

    // --- Outils --------------------------------------------------------------------------------------------

    private function assertOwnerWasAlerted(string $text, string $reason): void
    {
        // Par défaut, rien n'est envoyé au client.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(0, Message::where('direction', 'outbound')->count());

        $escalation = Escalation::sole();
        $this->assertSame($text, $escalation->customer_question);
        $this->assertSame($reason, $escalation->reason);
        $this->assertSame('pending', $escalation->status);

        $this->assertSame($text, Notification::where('type', 'escalation')->sole()->body);
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('joyce@example.test')
            && str_contains($mail->lines[0], $text)
            && $mail->actionUrl === 'https://getcauseo.com/dashboard/conversations?conversation='.$escalation->conversation_id);
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH.'CAUSEO-PHONE-ID/messages'
            && $r['template']['name'] === 'escalade_client'
            && $r['template']['components'][0]['parameters'][1]['text'] === $text);
    }

    private function unitsUsed(): int
    {
        return app(UsageService::class)->repliesSince($this->business, now()->subDay());
    }

    /**
     * @return array<string, mixed>
     */
    private function audioPayload(string $messageId = 'wamid.VOICE1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '22660000000', 'phone_number_id' => 'PNID-JOYCE'],
                    'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => self::CUSTOMER]],
                    'messages' => [[
                        'from' => self::CUSTOMER, 'id' => $messageId, 'timestamp' => '1761213600', 'type' => 'audio',
                        'audio' => ['mime_type' => 'audio/ogg; codecs=opus', 'sha256' => 'x', 'id' => 'MEDIA-1', 'voice' => true],
                    ]],
                ],
            ]],
        ]]];
    }

    /**
     * Faux fichier OGG/Opus : page d'en-tête OpusHead (pre-skip 312), données, puis
     * dernière page dont la position vaut la durée × 48 000 + pre-skip.
     */
    private function ogg(int $seconds): string
    {
        $page = fn (int $type, int $granule, string $data) => 'OggS'.chr(0).chr($type).pack('P', $granule)
            .pack('V', 1).pack('V', 0).pack('V', 0).chr(1).chr(strlen($data)).$data;

        $opusHead = 'OpusHead'.chr(1).chr(1).pack('v', 312).pack('V', 48000).pack('v', 0).chr(0);

        return $page(2, 0, $opusHead).str_repeat("\x55", 2000).$page(4, $seconds * 48000 + 312, '');
    }

    /**
     * @return list<string>
     */
    private function tempVoiceFiles(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'causeo-voice-*') ?: [];
    }
}
