<?php

namespace Tests\Feature;

use App\Jobs\IndexManagerReply;
use App\Jobs\SendEscalationFallback;
use App\Jobs\RefreshStyleProfile;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Conversations\SocialLinkService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use App\Support\EmojiStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FakeWhatsAppService;
use Tests\Support\FakeWhatsAppServiceFactory;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * Corrections tirées de vraies conversations de NEEB-RATA MARKET : pause quand le gérant
 * prend la main, messages hors vente, relances après une escalade, réponses aux statuts,
 * liens TikTok, façon de s'adresser au client et emojis.
 */
class AssistantHandoffTest extends TestCase
{
    use CreatesConversationSchema;

    private const GRAPH = 'https://graph.facebook.com/v21.0/';

    private const MEDIA_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/';

    private const CUSTOMER = '22670000001';

    private const CONTEXT = 'Robe wax Awa : 12 500 FCFA, tailles 38 à 44, en stock.';

    private const HANDOFF = 'Merci pour votre message, nous le transmettons au responsable qui vous répondra rapidement.';

    private const FOLLOW_UP = 'Votre demande a bien été transmise au responsable, il vous répond dès que possible.';

    private User $owner;

    private Business $business;

    /** Réponses successives de Claude ; la dernière se répète. */
    private array $claudeAnswers = ['La robe wax Awa est à 12 500 FCFA.'];

    /** Résumé renvoyé par Claude pour le gérant (null : erreur 500). */
    private ?string $summary = "Awa (WhatsApp) demande si vous livrez à Koudougou.\nIl manque : vos zones et frais de livraison.\nSuggestion : indiquer le prix de la livraison à Koudougou.";

    /** Réponse de l'oEmbed TikTok (null : erreur 500). */
    private ?array $tiktokOembed = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 10:00:00');
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            'services.whatsapp.token' => 'system-token',
            'services.whatsapp.api_url' => self::GRAPH,
            'services.causeo_notify.phone_number_id' => 'CAUSEO-PHONE-ID',
            'services.causeo_notify.escalation_cooldown_minutes' => 15,
            'services.reply_pacing.typing_jitter_seconds' => 0,
        ]);

        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_100000_add_whatsapp_alerts_to_users.php'))->up();
        (require base_path('database/migrations/2026_10_29_110000_add_whatsapp_alerts_consent_version.php'))->up();

        $this->owner = User::forceCreate([
            'name' => 'Gérant', 'email' => 'neeb@example.test', 'password' => 'x',
            'notification_phone' => '+22670112233', 'whatsapp_alerts_enabled' => true, 'whatsapp_alerts_consented_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'NEEB-RATA MARKET', 'modules' => '[]',
            'whatsapp_phone_number_id' => 'PNID-NEEB', 'whatsapp_verified' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);
        $this->business->forceFill(['whatsapp_token' => 'business-token'])->save();

        FakeWhatsAppService::$sent = [];
        FakeWhatsAppService::$read = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService(self::CONTEXT));

        Mail::fake();
        Queue::fake([IndexManagerReply::class, RefreshStyleProfile::class]);
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                self::isSummaryRequest($request) => $this->summary === null
                    ? Http::response(['error' => ['message' => 'overloaded']], 529)
                    : Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->summary]]]),
                str_contains($url, 'api.anthropic.com') => Http::response([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => count($this->claudeAnswers) > 1 ? array_shift($this->claudeAnswers) : $this->claudeAnswers[0]]],
                ]),
                str_starts_with($url, self::GRAPH.'MEDIA-') => Http::response(['url' => self::MEDIA_URL.'?mid=1', 'mime_type' => 'image/jpeg', 'file_size' => 2000]),
                str_starts_with($url, self::MEDIA_URL) => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
                str_starts_with($url, self::GRAPH.'CAUSEO-PHONE-ID/messages') => Http::response(['messages' => [['id' => 'wamid.ALERT']]]),
                str_starts_with($url, self::GRAPH.'PNID-NEEB/messages') => Http::response(['messages' => [['id' => 'wamid.DASHBOARD']]]),
                str_starts_with($url, 'https://vm.tiktok.com/') => Http::response('', 301, ['Location' => 'https://www.tiktok.com/@neebrata/video/7431']),
                str_starts_with($url, SocialLinkService::TIKTOK_OEMBED) => $this->tiktokOembed === null
                    ? Http::response('', 500)
                    : Http::response($this->tiktokOembed),
                str_starts_with($url, 'https://p16-sign-va.tiktokcdn.com/') => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response(['error' => ['message' => "URL inattendue : {$url}"]], 500),
            };
        });
    }

    // --- 1. Pause quand le gérant prend la main --------------------------------------------------

    public function test_after_the_owner_writes_from_his_phone_the_assistant_stays_silent_for_six_hours(): void
    {
        // Cas réel : le gérant écrit « Bonjour » puis envoie un vocal depuis son téléphone.
        $this->ownerEcho('wamid.E1', 'text', ['body' => 'Bonjour']);
        $this->ownerEcho('wamid.E2', 'audio', ['id' => 'AUDIO-1', 'mime_type' => 'audio/ogg', 'voice' => true]);

        $conversation = Conversation::sole();
        $this->assertTrue($conversation->ai_paused);
        $this->assertFalse($conversation->ai_paused_indefinitely);
        $this->assertSame('2026-10-28 16:00:00', $conversation->ai_paused_until->toDateTimeString());

        $this->receive('wamid.1', "D'accord merci");

        // Ni « De rien » ni « Que puis-je faire pour vous ? » : le message est gardé, sans réponse ni unité.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(Conversation::PAUSED_MESSAGE_STATUS, Message::where('whatsapp_message_id', 'wamid.1')->value('status'));
        $this->assertSame(0, (int) $this->business->fresh()->monthly_message_count);
        $this->assertSame([], FakeWhatsAppService::$read);
        $this->assertSame([], $this->claudeRequests());

        // La pause finie, l'assistant répond de nouveau.
        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->receive('wamid.2', 'La robe wax Awa est à combien ?');

        $this->assertSame(['La robe wax Awa est à 12 500 FCFA.'], FakeWhatsAppService::$sent);
    }

    public function test_each_owner_message_extends_the_pause(): void
    {
        $this->ownerEcho('wamid.E1', 'text', ['body' => 'Bonjour']);
        $this->travel(5)->hours();
        $this->ownerEcho('wamid.E2', 'text', ['body' => 'Je vous envoie les photos']);

        $this->assertSame('2026-10-28 21:00:00', Conversation::sole()->ai_paused_until->toDateTimeString());
    }

    public function test_a_reply_from_the_dashboard_pauses_the_assistant(): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/reply", ['response' => 'Bonjour, je regarde ça.'])->assertOk();

        $this->assertTrue($conversation->fresh()->ai_paused);
        $this->assertSame('2026-10-28 16:00:00', $conversation->fresh()->ai_paused_until->toDateTimeString());
    }

    public function test_the_pause_length_is_a_business_setting(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['ai_pause_hours' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ai_pause_hours' => 'La pause de l\'assistant dure 1, 6 ou 24 heures, ou jusqu\'à réactivation (0).']);

        $this->putJson("/api/v1/businesses/{$this->business->id}", ['ai_pause_hours' => 24])->assertOk()->assertJsonPath('business.ai_pause_hours', 24);
        $this->ownerEcho('wamid.E1', 'text', ['body' => 'Bonjour']);
        $this->assertSame('2026-10-29 10:00:00', Conversation::sole()->ai_paused_until->toDateTimeString());

        // 0 : jusqu'à ce que le gérant réactive l'assistant.
        $this->putJson("/api/v1/businesses/{$this->business->id}", ['ai_pause_hours' => 0])->assertOk();
        $this->ownerEcho('wamid.E2', 'text', ['body' => 'Je m\'en occupe']);
        $this->assertTrue(Conversation::sole()->ai_paused_indefinitely);
    }

    public function test_the_owner_pauses_and_reactivates_the_assistant_by_hand(): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/ai/pause")
            ->assertOk()
            ->assertJson(['ai_paused' => true, 'ai_paused_indefinitely' => true]);

        $this->travel(30)->days();
        $this->receive('wamid.1', 'La robe wax Awa est à combien ?');
        $this->assertSame([], FakeWhatsAppService::$sent);

        // Une réponse du gérant ne raccourcit pas une pause sans limite.
        $this->ownerEcho('wamid.E1', 'text', ['body' => 'Je vous réponds tout de suite']);
        $this->assertTrue($conversation->fresh()->ai_paused_indefinitely);

        $this->postJson("/api/v1/conversations/{$conversation->id}/ai/resume")
            ->assertOk()
            ->assertJson(['ai_paused' => false, 'ai_paused_until' => null]);

        $this->travel(1)->minutes();
        $this->receive('wamid.2', 'La robe wax Awa est à combien ?');
        $this->assertSame(['La robe wax Awa est à 12 500 FCFA.'], FakeWhatsAppService::$sent);

        $this->postJson("/api/v1/conversations/{$conversation->id}/ai/pause", ['hours' => 1])
            ->assertOk()
            ->assertJsonPath('ai_paused_until', now()->addHour()->toIso8601String());
        $this->getJson("/api/v1/conversations/{$conversation->id}")->assertJsonPath('conversation.ai_paused', true);
        $this->getJson("/api/v1/businesses/{$this->business->id}/conversations")->assertJsonPath('data.0.ai_paused', true);
    }

    public function test_another_owner_cannot_pause_the_conversation(): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER]);
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));

        $this->postJson("/api/v1/conversations/{$conversation->id}/ai/pause")->assertForbidden();
        $this->assertFalse($conversation->fresh()->ai_paused);
    }

    // --- 2. Messages hors vente -----------------------------------------------------------------

    public function test_a_partnership_proposal_gets_one_neutral_sentence_and_goes_to_the_owner(): void
    {
        $this->claudeAnswers = ["Merci pour votre proposition ! Nous serions ravis d'en discuter.\nHORS_VENTE: partenariat"];

        $this->receive('wamid.1', 'Bonjour, je suis influenceuse à Ouaga et je vous propose une collaboration pour présenter vos articles sur ma page.');

        $this->assertSame([self::HANDOFF], FakeWhatsAppService::$sent);
        $escalation = Escalation::sole();
        $this->assertSame(Escalation::REASON_PARTNERSHIP, $escalation->reason);
        $this->assertSame('Proposition de partenariat de Awa', Notification::where('type', 'escalation')->sole()->title);
        $this->assertStringContainsString('# Messages hors vente', $this->claudeRequests()[0]['system'][0]['text']);
    }

    public function test_a_claim_about_an_old_order_is_short_and_invents_nothing(): void
    {
        $this->receive('wamid.1', "Bonsoir, j'ai déposé un pagne pour une confection il y a deux semaines, c'est prêt ?");

        // Reconnue sans appel à Claude : une phrase, puis escalade.
        $this->assertSame([self::HANDOFF], FakeWhatsAppService::$sent);
        $this->assertSame(Escalation::REASON_PAST_ORDER, Escalation::sole()->reason);
        $this->assertSame([], $this->claudeRequests());
    }

    public function test_a_complaint_is_short_and_apologizes_once(): void
    {
        $this->claudeAnswers = ["Je suis vraiment désolée pour ce souci. Je transmets votre message au responsable, qui revient vers vous rapidement. "
            ."Encore toutes nos excuses. Nous allons vous rembourser. Merci de votre patience.\nHORS_VENTE: plainte"];

        $this->receive('wamid.1', 'La robe que vous m\'avez livrée hier est déchirée, je suis très déçue.');

        $this->assertSame(["Je suis vraiment désolée pour ce souci. Je transmets votre message au responsable, qui revient vers vous rapidement. Encore toutes nos excuses."], FakeWhatsAppService::$sent);
        $this->assertSame(Escalation::REASON_COMPLAINT, Escalation::sole()->reason);
        $this->assertStringContainsString('reclamation ou plainte : 2 ou 3 phrases au plus. Des excuses une seule fois au plus', $this->claudeRequests()[0]['system'][0]['text']);
    }

    public function test_a_personal_message_with_a_photo_of_money_gets_no_reply_and_no_description(): void
    {
        $this->claudeAnswers = ['HORS_VENTE: personnel'];

        $this->receiveRaw([
            'id' => 'wamid.1', 'type' => 'image',
            'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'caption' => 'Monsieur Kola est malade, on a pris le lot'],
        ]);

        $this->assertSame([], FakeWhatsAppService::$sent);
        $escalation = Escalation::sole();
        $this->assertSame(Escalation::REASON_PERSONAL, $escalation->reason);
        $this->assertSame('escalated', Message::where('whatsapp_message_id', 'wamid.1')->value('status'));
        $this->assertSame(0, (int) $this->business->fresh()->monthly_message_count);
        $this->assertSame('Message personnel de Awa', Notification::where('type', 'escalation')->sole()->title);

        // La photo est montrée à l'assistant, qui ne doit ni la décrire ni deviner ce qu'elle montre.
        $request = $this->claudeRequests()[0];
        $this->assertSame('image', end($request['messages'])['content'][0]['type']);
        $this->assertStringContainsString("Si la photo ne montre pas clairement un article du catalogue (billets ou pièces de monnaie, document, personne, lieu, capture sans rapport, photo floue…), ne dis jamais ce qu'elle montre et n'identifie aucun objet : ne devine pas.", $request['system'][1]['text']);
    }

    // --- 3. Escalade silencieuse et relances -------------------------------------------------------

    public function test_when_the_assistant_does_not_know_nothing_is_sent_and_the_owner_is_alerted(): void
    {
        // Même avec un message d'attente écrit par Claude avant le marqueur : rien ne part.
        $this->claudeAnswers = ["Nous vérifions et revenons vers vous très vite\nJE_NE_SAIS_PAS"];

        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');

        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(0, Message::where('direction', 'outbound')->count());
        $this->assertSame('escalated', Message::where('whatsapp_message_id', 'wamid.1')->value('status'));
        $escalation = Escalation::sole();
        $this->assertTrue($escalation->silent);
        $this->assertSame(Escalation::PENDING, $escalation->status);
        $this->assertSame('2026-10-28 10:15:00', $escalation->fallback_due_at->toDateTimeString());
        $this->assertNull($escalation->fallback_sent_at);
        $this->assertSame('Awa attend votre réponse', Notification::where('type', 'escalation')->sole()->title);
        $this->assertSame(1, $this->alertsSent());

        // Le prompt ne fait plus écrire de message d'attente.
        $stable = $this->claudeRequests()[0]['system'][0]['text'];
        $this->assertStringContainsString("n'invente rien et n'écris rien au client : écris seulement le marqueur technique JE_NE_SAIS_PAS, seul.", $stable);
        $this->assertStringContainsString('Ne parle jamais au client de gérant, de responsable, de transmission ni de vérification.', $stable);
        $this->assertStringNotContainsString('Nous vérifions et revenons vers vous très vite', $stable);

        // Le dashboard voit la conversation en attente du gérant.
        Sanctum::actingAs($this->owner);
        $listed = $this->getJson("/api/v1/businesses/{$this->business->id}/conversations?filter=pending")->assertOk()->json('data.0.escalations.0');
        $this->assertTrue($listed['silent']);
        $this->assertNotNull($listed['fallback_due_at']);
        $this->assertNull($listed['fallback_sent_at']);
    }

    public function test_without_an_answer_from_the_owner_one_neutral_message_is_sent_after_fifteen_minutes(): void
    {
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $escalation = Escalation::sole();

        // Trop tôt : rien.
        $this->travel(14)->minutes();
        $this->runFallback($escalation);
        $this->assertSame([], FakeWhatsAppService::$sent);

        $this->travel(1)->minutes();
        $this->runFallback($escalation);
        $this->assertSame(['Merci pour votre patience, nous revenons vers vous au plus vite.'], FakeWhatsAppService::$sent);
        $this->assertNotNull($escalation->fresh()->fallback_sent_at);
        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame('ai', $message->sender_type);
        $this->assertSame($escalation->id, $message->metadata['escalation_fallback']);
        $this->assertSame(Escalation::PENDING, $escalation->fresh()->status);

        // Une seule fois, même relancé, et pas de nouveau message pour une deuxième escalade.
        $this->runFallback($escalation);
        $this->receive('wamid.2', 'Et à Bobo ?');
        $this->travel(20)->minutes();
        $this->runFallback(Escalation::latest()->orderByDesc('id')->first());
        $this->assertCount(1, FakeWhatsAppService::$sent);
    }

    public function test_the_neutral_message_is_cancelled_when_the_owner_answers_first(): void
    {
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $escalation = Escalation::sole();

        $this->travel(5)->minutes();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/conversations/'.Conversation::sole()->id.'/reply', ['response' => 'Oui, 2 000 F la livraison.'])->assertOk();

        $this->travel(15)->minutes();
        $this->runFallback($escalation);

        $this->assertSame(['Oui, 2 000 F la livraison.'], FakeWhatsAppService::$sent);
        $this->assertNull($escalation->fresh()->fallback_sent_at);
    }

    public function test_the_owner_sets_the_delay_or_never_sends_the_neutral_message(): void
    {
        Sanctum::actingAs($this->owner);
        $this->assertSame(15, $this->business->fresh()->escalation_fallback_minutes);
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['escalation_fallback_minutes' => 1441])
            ->assertUnprocessable()->assertJsonValidationErrors('escalation_fallback_minutes');
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['escalation_fallback_minutes' => -1])->assertUnprocessable();
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['escalation_fallback_minutes' => 30])
            ->assertOk()->assertJsonPath('business.escalation_fallback_minutes', 30);

        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $this->assertSame('2026-10-28 10:30:00', Escalation::sole()->fallback_due_at->toDateTimeString());

        // 0 : jamais de message d'attente.
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['escalation_fallback_minutes' => 0])->assertOk();
        $this->receive('wamid.2', 'Et à Bobo ?', 'Awa', '22671000009');
        $second = Escalation::where('conversation_id', '!=', Escalation::oldest()->first()->conversation_id)->sole();
        $this->assertTrue($second->silent);
        $this->assertNull($second->fallback_due_at);
        $this->travel(2)->hours();
        $this->runFallback($second);
        $this->assertSame([], FakeWhatsAppService::$sent);
    }

    public function test_a_follow_up_on_a_silent_escalation_stays_silent_and_alerts_the_owner_again(): void
    {
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $this->assertSame(1, $this->alertsSent());

        $this->travel(2)->minutes();
        $this->receive('wamid.2', 'Je suis toujours en attente');

        // Ni réponse, ni nouvel appel à Claude, ni nouvelle escalade.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertCount(1, $this->claudeRequests());
        $this->assertSame(1, Escalation::count());
        $this->assertSame('escalated', Message::where('whatsapp_message_id', 'wamid.2')->value('status'));
        $notification = Notification::where('type', 'escalation')->sole();
        $this->assertSame('Relance de Awa : demande toujours en attente', $notification->title);
        $this->assertSame('Vous livrez à Koudougou ?', $notification->body);
        // Anti-inondation : une alerte WhatsApp au plus par quart d'heure.
        $this->assertSame(1, $this->alertsSent());

        $this->travel(20)->minutes();
        $this->receive('wamid.3', 'Ma requête svp');
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(2, $this->alertsSent());
    }

    public function test_the_silent_pending_question_is_given_to_the_assistant_without_the_owner(): void
    {
        $this->claudeAnswers = ['JE_NE_SAIS_PAS', 'RELANCE'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $this->travel(2)->minutes();
        $this->receive('wamid.2', 'Vous avez vu mon message de tout à l\'heure ?');

        $system = $this->claudeRequests()[1]['system'][1]['text'];
        $this->assertStringContainsString("# Demande en cours de traitement\nLe client attend une réponse à cette demande : « Vous livrez à Koudougou ? ».", $system);
        $this->assertStringNotContainsString('responsable', $system);
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(1, Escalation::count());
        $this->assertSame('Relance de Awa : demande toujours en attente', Notification::where('type', 'escalation')->sole()->title);
    }

    public function test_a_follow_up_on_a_complaint_still_gets_the_reassuring_reply(): void
    {
        $this->claudeAnswers = ["Toutes nos excuses pour ce retard.\nHORS_VENTE: plainte"];
        $this->receive('wamid.1', 'Ma robe reçue hier est déchirée, ce n\'est pas sérieux');
        $this->assertFalse(Escalation::sole()->silent);
        $this->assertNull(Escalation::sole()->fallback_due_at);

        $this->travel(2)->minutes();
        $this->receive('wamid.2', 'Je suis toujours en attente');

        $this->assertSame(self::FOLLOW_UP, end(FakeWhatsAppService::$sent));
    }

    // --- Résumé pour le gérant -----------------------------------------------------------------------

    public function test_the_owner_gets_a_short_summary_in_the_notification_and_the_whatsapp_alert(): void
    {
        config(['services.anthropic.escalation_summary' => true, 'services.anthropic.summary_model' => 'claude-haiku-test']);
        $logged = [];
        Log::listen(function (MessageLogged $log) use (&$logged) {
            $logged[] = $log->message.' '.json_encode($log->context);
        });
        $this->claudeAnswers = ['Bonjour, que pouvons-nous faire pour vous ?', 'JE_NE_SAIS_PAS'];

        $this->receive('wamid.0', 'Bonjour, je suis Awa, mon autre numéro est le 76 54 32 10.');
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');

        $escalation = Escalation::sole();
        $this->assertSame($this->summary, $escalation->summary);

        // Notification du dashboard : le résumé, et la question brute à côté.
        $notification = Notification::where('type', 'escalation')->sole();
        $this->assertSame($this->summary, $notification->body);
        $this->assertSame($this->summary, $notification->data['summary']);
        $this->assertSame($escalation->customer_question, $notification->data['question']);

        // Alerte WhatsApp : le résumé sur une ligne à la place de la question.
        $alert = Http::recorded()->first(fn (array $pair) => str_starts_with($pair[0]->url(), self::GRAPH.'CAUSEO-PHONE-ID/messages'))[0];
        $this->assertSame(
            'Awa (WhatsApp) demande si vous livrez à Koudougou. Il manque : vos zones et frais de livraison. Suggestion : indiquer le prix de la livraison à Koudougou.',
            $alert['template']['components'][0]['parameters'][1]['text'],
        );

        // Claude Haiku reçoit le nom WhatsApp, la conversation, jamais un numéro.
        $request = $this->summaryRequests()[0];
        $this->assertSame('claude-haiku-test', $request['model']);
        $this->assertLessThanOrEqual(200, $request['max_tokens']);
        $content = $request['messages'][0]['content'];
        $this->assertStringContainsString('Nom WhatsApp du client : Awa', $content);
        $this->assertStringContainsString('Demande en attente : Vous livrez à Koudougou ?', $content);
        $this->assertStringContainsString('Entreprise : NEEB-RATA MARKET', $content);
        $this->assertStringNotContainsString(self::CUSTOMER, json_encode($request));
        $this->assertStringNotContainsString('76 54 32 10', $content);
        $this->assertStringContainsString('[numéro]', $content);

        // Ni numéro ni résumé dans les logs.
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::CUSTOMER, $line);
            $this->assertStringNotContainsString('Koudougou', $line);
        }
    }

    public function test_the_summary_never_keeps_a_phone_number_and_is_three_lines_at_most(): void
    {
        config(['services.anthropic.escalation_summary' => true]);
        $this->summary = "**Résumé**\nAwa (WhatsApp, +226 70 00 00 01) veut une robe à 1 500 000 F.\nIl manque : le stock.\nSuggestion : la rappeler.\nMerci !";
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];

        $this->receive('wamid.1', 'Vous avez la robe de mariée ?');

        $this->assertSame("Résumé\nAwa (WhatsApp, [numéro]) veut une robe à 1 500 000 F.\nIl manque : le stock.", Escalation::sole()->summary);
    }

    public function test_without_a_summary_the_escalation_still_alerts_the_owner_with_the_question(): void
    {
        config(['services.anthropic.escalation_summary' => true]);
        $this->summary = null;
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];

        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');

        $this->assertNull(Escalation::sole()->summary);
        $this->assertSame('Vous livrez à Koudougou ?', Notification::where('type', 'escalation')->sole()->body);
        $this->assertSame(1, $this->alertsSent());
        $this->assertSame([], FakeWhatsAppService::$sent);
    }

    public function test_no_summary_for_an_answered_or_personal_message(): void
    {
        config(['services.anthropic.escalation_summary' => true]);
        $this->claudeAnswers = ['La robe wax Awa est à 12 500 FCFA.'];
        $this->receive('wamid.1', 'La robe wax Awa est à combien ?');

        $this->claudeAnswers = ["\nHORS_VENTE: personnel"];
        $this->receive('wamid.2', 'Tonton, maman te salue, on a pris le lot.');

        $this->assertSame([], $this->summaryRequests());
        $this->assertNull(Escalation::sole()->summary);
    }

    public function test_a_real_question_during_a_pending_escalation_is_still_answered(): void
    {
        $this->claudeAnswers = ['JE_NE_SAIS_PAS', 'La robe wax Awa est à 12 500 FCFA.'];
        $this->receive('wamid.1', 'Vous livrez à Koudougou ?');
        $this->receive('wamid.2', 'Et la robe wax Awa, combien svp ?');

        $this->assertSame(['La robe wax Awa est à 12 500 FCFA.'], FakeWhatsAppService::$sent);
        $this->assertCount(2, $this->claudeRequests());
    }

    // --- 4. Réponses aux statuts et questions sans référence ------------------------------------

    public function test_a_reply_to_a_status_published_outside_causeo_needing_its_price_goes_silently_to_the_owner(): void
    {
        config(['services.anthropic.escalation_summary' => true]);
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];

        $this->receiveRaw([
            'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => "C'est combien ?"],
            'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-1'],
        ]);

        // Ni capture demandée, ni réponse inventée : le gérant prend le relais.
        $this->assertSame([], FakeWhatsAppService::$sent);
        $note = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertStringContainsString('probablement un statut WhatsApp de la boutique publié hors Causeo', $note);
        $this->assertStringContainsString('Ne fais pas semblant de le connaître et ne demande pas de capture.', $note);
        $this->assertStringContainsString("Si la réponse dépend de l'article ou du prix de ce statut, écris seulement JE_NE_SAIS_PAS.", $note);
        $this->assertStringNotContainsString('capture de l\'article', $note);

        $escalation = Escalation::sole();
        $this->assertSame(Escalation::REASON_STATUS_REPLY, $escalation->reason);
        $this->assertTrue($escalation->silent);
        $this->assertSame('Réponse de Awa à un statut à traiter', Notification::where('type', 'escalation')->sole()->title);
        $this->assertStringContainsString('réponse à un statut publié hors Causeo', $this->summaryRequests()[0]['messages'][0]['content']);
        $this->assertSame(1, Notification::where('type', 'status')->count());
    }

    public function test_a_reply_to_an_unknown_status_is_answered_with_what_the_assistant_knows(): void
    {
        $this->claudeAnswers = ['Oui, nous sommes ouverts samedi de 9h à 18h.'];

        $this->receiveRaw([
            'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Vous êtes ouverts samedi ?'],
            'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-1'],
        ]);

        $this->assertSame(['Oui, nous sommes ouverts samedi de 9h à 18h.'], FakeWhatsAppService::$sent);
        $this->assertSame(0, Escalation::count());
        $this->assertArrayNotHasKey('clarification', Message::where('direction', 'outbound')->sole()->metadata);
    }

    public function test_after_two_unsuccessful_clarifications_the_assistant_hands_over(): void
    {
        $this->claudeAnswers = [
            "De quel article parlez-vous ?\nPRECISION",
            "Pouvez-vous me donner le nom de l'article ?\nPRECISION",
            "Pouvez-vous m'envoyer une photo ?\nPRECISION",
        ];

        $this->receive('wamid.1', 'Je veux le truc de la dernière fois');
        $this->receive('wamid.2', 'Celui que tout le monde porte');
        $this->assertCount(2, FakeWhatsAppService::$sent);
        $this->assertSame(0, Escalation::count());

        $this->receive('wamid.3', 'Vous savez bien lequel');

        $this->assertStringContainsString('Tu as déjà demandé des précisions 2 fois de suite. Ne repose pas de question', $this->claudeRequests()[2]['system'][1]['text']);
        // Le gérant prend le relais sans que le client en soit informé.
        $this->assertCount(2, FakeWhatsAppService::$sent);
        $this->assertSame(Escalation::REASON_CLARIFICATION, Escalation::sole()->reason);
        $this->assertTrue(Escalation::sole()->silent);
    }

    // --- 5. Liens TikTok, Instagram, Facebook ---------------------------------------------------

    public function test_a_tiktok_link_is_read_with_oembed_and_shown_like_a_photo(): void
    {
        $this->tiktokOembed = ['title' => 'Nouvelle robe wax Awa disponible #wax', 'thumbnail_url' => 'https://p16-sign-va.tiktokcdn.com/obj/robe.jpeg'];

        $this->receive('wamid.1', 'Bonjour, je veux ça https://vm.tiktok.com/ZMabc123/');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), SocialLinkService::TIKTOK_OEMBED)
            && $request['url'] === 'https://www.tiktok.com/@neebrata/video/7431');
        [$image, $text] = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertSame('image', $image['type']);
        $this->assertStringContainsString("Le client a envoyé un lien vers une vidéo TikTok (légende : « Nouvelle robe wax Awa disponible #wax »). L'image de la vidéo est jointe au message", $text['text']);
        $this->assertSame(['La robe wax Awa est à 12 500 FCFA.'], FakeWhatsAppService::$sent);
    }

    public function test_an_unreadable_tiktok_or_an_instagram_link_asks_for_a_capture(): void
    {
        $this->receive('wamid.1', 'Celui-ci https://www.tiktok.com/@neebrata/video/7431 et aussi instagram.com/p/Cabc123');

        $text = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertIsString($text);
        $this->assertStringContainsString("Le client a envoyé un lien TikTok que tu ne peux pas ouvrir. Ne devine pas ce qu'il montre : demande-lui simplement une capture d'écran", $text);
        $this->assertStringContainsString('Le client a envoyé un lien Instagram que tu ne peux pas ouvrir.', $text);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'instagram.com'));
    }

    public function test_social_links_are_found_with_or_without_https(): void
    {
        $this->assertSame([
            ['TikTok', 'https://vm.tiktok.com/ZMabc123/'],
            ['Facebook', 'https://www.facebook.com/neebrata/posts/1'],
            ['Instagram', 'https://instagram.com/p/Cabc123'],
        ], SocialLinkService::links('vm.tiktok.com/ZMabc123/ et https://www.facebook.com/neebrata/posts/1, puis instagram.com/p/Cabc123.'));
        $this->assertSame([], SocialLinkService::links('https://tiktok.com.evil.test/x'));
    }

    // --- 6. Façon de s'adresser au client ------------------------------------------------------

    public function test_the_customer_is_never_called_by_name(): void
    {
        $this->claudeAnswers = ['Bonjour Kadi, la robe wax Awa est à 12 500 FCFA. Merci Kadi Sawadogo !'];

        $this->receive('wamid.1', 'Bonjour, la robe wax Awa est à combien ?', 'Kadi Sawadogo');

        $this->assertSame(['Bonjour, la robe wax Awa est à 12 500 FCFA. Merci !'], FakeWhatsAppService::$sent);
        $system = $this->claudeRequests()[0]['system'];
        $this->assertStringContainsString("N'appelle jamais le client par son prénom ni par son nom", $system[0]['text']);
        $this->assertStringContainsString("si le client demande sincèrement s'il parle à une IA ou à un robot, ne le nie JAMAIS", $system[0]['text']);
        $this->assertStringNotContainsString('# Civilité du client', $system[1]['text']);
    }

    public function test_madame_or_monsieur_only_when_the_profile_name_says_so(): void
    {
        $this->claudeAnswers = ['Bonjour Madame Ouédraogo, la robe est à 12 500 FCFA.'];

        $this->receive('wamid.1', 'La robe wax Awa est à combien ?', 'Mme Ouédraogo');

        $this->assertStringContainsString("# Civilité du client\nSon nom de profil WhatsApp indique clairement : Madame.", $this->claudeRequests()[0]['system'][1]['text']);
        $this->assertSame(['Bonjour Madame, la robe est à 12 500 FCFA.'], FakeWhatsAppService::$sent);

        $this->assertSame('Monsieur', AIResponseService::customerCivility('M. Kaboré'));
        $this->assertSame('Madame', AIResponseService::customerCivility('Madame Bintou'));
        $this->assertNull(AIResponseService::customerCivility('Kola'));
        $this->assertNull(AIResponseService::customerCivility('Awa M'));
    }

    // --- 7. Emojis et longueur -----------------------------------------------------------------

    public function test_canned_replies_carry_no_emoji_by_default(): void
    {
        // Par le webhook, comme en production (business sans réglage d'emojis).
        $this->receive('wamid.T1', 'Merci beaucoup', null, '22671000001');
        $this->receive('wamid.G1', 'Bonjour', null, '22672000001');
        $this->receive('wamid.B1', 'Au revoir', null, '22673000001');
        $this->assertCount(3, FakeWhatsAppService::$sent);
        $this->assertSame([], $this->claudeRequests());

        // Les réponses toutes faites sont tirées au hasard : chacune passe par le même filtre (ReplyToCustomer).
        $ai = app(AIResponseService::class);
        $sent = FakeWhatsAppService::$sent;
        foreach (range(1, 20) as $i) {
            foreach (['Merci beaucoup', 'Bonjour', 'Au revoir'] as $question) {
                $sent[] = EmojiStyle::apply($this->business, (string) $ai->answer($this->business, $question)['answer']);
            }
        }

        foreach ($sent as $text) {
            // Seule exception gardée par la correction précédente : un 👋 pour saluer (ni 🙏 ni 👍).
            $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', str_replace('👋', '', $text), $text);
        }
        $this->assertContains('De rien', $sent);
    }

    public function test_the_neutral_message_carries_no_emoji_and_says_vous_even_with_tu(): void
    {
        $this->business->update(['reply_style' => ['address' => 'tu', 'emojis' => 'often']]);
        $this->claudeAnswers = ['JE_NE_SAIS_PAS'];

        $this->receive('wamid.1', 'Tu livres à Koudougou ?');
        $this->travel(15)->minutes();
        $this->runFallback(Escalation::sole());

        $this->assertSame(['Merci pour votre patience, nous revenons vers vous au plus vite.'], FakeWhatsAppService::$sent);
    }

    // --- Outils -------------------------------------------------------------------------------------

    private function receive(string $messageId, string $text, ?string $name = 'Awa', string $from = self::CUSTOMER): void
    {
        $this->receiveRaw(['id' => $messageId, 'type' => 'text', 'text' => ['body' => $text]], $name, $from);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function receiveRaw(array $message, ?string $name = 'Awa', string $from = self::CUSTOMER): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-NEEB'],
                        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
                        'messages' => [['from' => $from] + $message],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    /**
     * Message envoyé par le gérant depuis l'application WhatsApp Business (écho).
     *
     * @param  array<string, mixed>  $content
     */
    private function ownerEcho(string $messageId, string $type, array $content): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'smb_message_echoes',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-NEEB'],
                        'message_echoes' => [['from' => '22625000000', 'to' => self::CUSTOMER, 'id' => $messageId, 'type' => $type, $type => $content]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function isSummaryRequest(Request $request): bool
    {
        return str_contains($request->url(), 'api.anthropic.com') && str_contains((string) json_encode($request->data()['system'] ?? '', JSON_UNESCAPED_UNICODE), 'résumé de 2 ou 3 lignes');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function summaryRequests(): array
    {
        return Http::recorded()->filter(fn (array $pair) => self::isSummaryRequest($pair[0]))->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function claudeRequests(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.anthropic.com') && ! self::isSummaryRequest($pair[0]))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function runFallback(Escalation $escalation): void
    {
        SendEscalationFallback::dispatchSync($escalation->id);
    }

    private function alertsSent(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_starts_with($pair[0]->url(), self::GRAPH.'CAUSEO-PHONE-ID/messages'))->count();
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(200, 200));

        return (string) ob_get_clean();
    }
}
