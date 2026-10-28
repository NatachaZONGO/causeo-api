<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCustomer;
use App\Jobs\SendCustomerReply;
use App\Mail\OwnerNotificationMail;
use App\Models\AdminAccessLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\ManagerReply;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Billing\UsageService;
use App\Services\WhatsApp\MediaMessage;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
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
 * Photos, documents, vidéos et autocollants reçus sur WhatsApp : enregistrement,
 * lecture par Claude des photos, preuves de paiement, escalades et affichage.
 */
class MediaMessageTest extends TestCase
{
    use CreatesConversationSchema;

    private const GRAPH = 'https://graph.facebook.com/v21.0/';

    private const MEDIA_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/';

    private const CUSTOMER = '22670000001';

    private const CONTEXT = 'Robe wax Awa : 12 500 FCFA, tailles 38 à 44, en stock.';

    private User $owner;

    private Business $business;

    /** Fichiers « chez Meta » : media_id => [mime_type, body]. */
    private array $files = [];

    /** Médias expirés chez Meta. */
    private bool $mediaExpired = false;

    private string $claudeAnswer = 'Oui, la robe wax Awa est à 12 500 FCFA, disponible en 42.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-25 10:00:00');
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            'services.whatsapp.token' => 'system-token',
            'services.whatsapp.api_url' => self::GRAPH,
            'services.causeo_notify.phone_number_id' => 'CAUSEO-PHONE-ID',
            'services.reply_pacing.typing_jitter_seconds' => 0,
            'app.frontend_url' => 'https://getcauseo.com',
        ]);

        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_100000_add_whatsapp_alerts_to_users.php'))->up();
        (require base_path('database/migrations/2026_10_23_110000_create_admin_access_logs_table.php'))->up();
        (require base_path('database/migrations/2026_10_24_110000_add_conversation_id_to_admin_access_logs.php'))->up();
        Schema::create('learned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('escalation_id')->nullable();
            $table->text('question');
            $table->text('answer');
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });
        // La vraie migration utilise pgvector : même table sans la colonne d'embedding.
        Schema::create('business_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('public_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->json('keywords');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->owner = User::forceCreate([
            'name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x',
            'notification_phone' => '+22670112233', 'whatsapp_alerts_enabled' => true, 'whatsapp_alerts_consented_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '["orders"]', 'delivery_enabled' => true,
            'whatsapp_phone_number_id' => 'PNID-JOYCE', 'whatsapp_verified' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);
        $this->business->forceFill(['whatsapp_token' => 'business-token'])->save();

        DB::table('business_media')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $id, 'type' => 'image', 'title' => 'Robe wax Awa',
            'description' => 'Robe longue en wax rouge et jaune', 'file_path' => 'robe.jpg', 'keywords' => '["robe","wax"]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        FakeWhatsAppService::$sent = [];
        FakeWhatsAppService::$read = [];
        FakeWhatsAppService::$images = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService(self::CONTEXT));

        Mail::fake();
        Http::fake(function (Request $request) {
            $url = $request->url();
            $mediaId = str_starts_with($url, self::GRAPH.'MEDIA-') ? substr($url, strlen(self::GRAPH)) : null;

            return match (true) {
                $this->mediaExpired && ($mediaId !== null || str_starts_with($url, self::MEDIA_URL)) => Http::response(['error' => ['message' => 'Media not found']], 404),
                $mediaId !== null => Http::response([
                    'url' => self::MEDIA_URL.'?mid='.$mediaId,
                    'mime_type' => $this->files[$mediaId][0],
                    'file_size' => strlen($this->files[$mediaId][1]),
                    'id' => $mediaId,
                ]),
                str_starts_with($url, self::MEDIA_URL) => Http::response(
                    $this->files[substr($url, strlen(self::MEDIA_URL.'?mid='))][1],
                    200,
                    ['Content-Type' => $this->files[substr($url, strlen(self::MEDIA_URL.'?mid='))][0]],
                ),
                str_contains($url, 'api.anthropic.com') => Http::response([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => $this->claudeAnswer]],
                    'usage' => ['input_tokens' => 1600, 'output_tokens' => 40],
                ]),
                str_starts_with($url, self::GRAPH.'CAUSEO-PHONE-ID/messages') => Http::response(['messages' => [['id' => 'wamid.ALERT']]]),
                str_starts_with($url, 'https://scontent.xx.fbcdn.net/') => Http::response($this->jpeg(300, 300), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response(['error' => ['message' => "URL inattendue : {$url}"]], 500),
            };
        });
    }

    // --- Photos lues par l'assistant -----------------------------------------------------------

    public function test_a_photo_is_recorded_reduced_shown_to_claude_and_answered(): void
    {
        $this->files['MEDIA-1'] = ['image/png', $this->png(2000, 1000)];

        $this->receive('wamid.IMG1', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/png', 'caption' => 'Vous avez celle-ci en 42 ?']);

        $inbound = Message::where('direction', 'inbound')->sole();
        $this->assertSame('Vous avez celle-ci en 42 ?', $inbound->content);
        $this->assertSame([
            'type' => 'image', 'media_id' => 'MEDIA-1', 'mime_type' => 'image/png',
            'caption' => 'Vous avez celle-ci en 42 ?', 'size' => strlen($this->files['MEDIA-1'][1]),
        ], $inbound->metadata);
        $this->assertSame('answered_by_ai', $inbound->status);
        $this->assertSame(['message_id' => 'wamid.IMG1', 'typing' => true], FakeWhatsAppService::$read[0]);

        // La photo, réduite à 1024 px de large, précède le texte et le catalogue.
        $request = $this->claudeRequests()[0];
        [$image, $text] = end($request['messages'])['content'];
        $this->assertSame('image', $image['type']);
        $this->assertSame('image/jpeg', $image['source']['media_type']);
        $this->assertSame([1024, 512], array_slice(getimagesizefromstring(base64_decode($image['source']['data'])), 0, 2));
        $this->assertStringContainsString('Question du client : Vous avez celle-ci en 42 ?', $text['text']);
        $this->assertStringContainsString('- Robe wax Awa — Robe longue en wax rouge et jaune (mots-clés : robe, wax)', $text['text']);
        $system = $this->systemPrompt($request);
        $this->assertStringContainsString('# Photo(s) du client', $system);
        $this->assertStringContainsString('ne confirme JAMAIS le paiement', $system);
        $this->assertStringContainsString('proposer de passer commande', $system);

        $this->assertSame([$this->claudeAnswer], FakeWhatsAppService::$sent);
        $outbound = Message::where('direction', 'outbound')->sole();
        $this->assertSame(1600, $outbound->metadata['ai_usage']['input_tokens']);
        $this->assertSame(1, app(UsageService::class)->repliesSince($this->business, now()->subDay()));
    }

    public function test_catalog_products_join_the_illustrated_catalog_for_customer_photos(): void
    {
        $this->business->update(['modules' => ['orders', 'catalog']]);
        $mediaId = DB::table('business_media')->value('id');
        app(\App\Services\Catalog\CatalogService::class)->saveProduct($this->business->fresh(), ['name' => 'Robe wax Awa', 'base_price' => 12500, 'media_ids' => [$mediaId]], null, [
            ['options' => ['taille' => '42'], 'stock_quantity' => 1],
        ]);
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(400, 300)];

        $this->receive('wamid.IMG1', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);

        $request = $this->claudeRequests()[0];
        $this->assertContains('search_catalog', array_column($request['tools'], 'name'));
        $this->assertStringContainsString(
            "(id: {$mediaId}, type: image) → article du catalogue « Robe wax Awa », 12 500 FCFA, dernières pièces",
            end($request['messages'])['content'][1]['text'],
        );
    }

    public function test_photos_sent_in_a_row_are_shown_together_in_one_reply(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(800, 600)];
        $this->files['MEDIA-2'] = ['image/jpeg', $this->jpeg(600, 800)];

        $this->receive('wamid.IMG1', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);
        $this->travel(3)->seconds();
        $this->receive('wamid.IMG2', 'image', ['id' => 'MEDIA-2', 'mime_type' => 'image/jpeg', 'caption' => 'Prix des deux ?']);

        $this->travel(8)->seconds();
        $this->app->call([Queue::pushed(ReplyToCustomer::class)->last(), 'handle']);
        $this->app->call([Queue::pushed(SendCustomerReply::class)->sole(), 'handle']);

        $requests = $this->claudeRequests();
        $this->assertCount(1, $requests);
        $content = end($requests[0]['messages'])['content'];
        $this->assertSame(['image', 'image', 'text'], array_column($content, 'type'));
        $this->assertStringContainsString("Question du client : 📷 Photo\nPrix des deux ?", $content[2]['text']);
        $this->assertStringContainsString('Le client a envoyé 2 photos', $this->systemPrompt($requests[0]));

        $this->assertSame([$this->claudeAnswer], FakeWhatsAppService::$sent);
        $this->assertSame(1, app(UsageService::class)->repliesSince($this->business, now()->subDay()));
    }

    public function test_an_expired_photo_is_not_shown_and_claude_is_told(): void
    {
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(400, 300)];
        $this->mediaExpired = true;
        $this->claudeAnswer = "Je n'arrive pas à ouvrir votre photo, pouvez-vous me dire quel article vous intéresse ?";

        $this->receive('wamid.IMG1', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);

        $request = $this->claudeRequests()[0];
        $this->assertIsString(end($request['messages'])['content']);
        $this->assertStringContainsString("1 photo(s) qui n'ont pas pu être affichées", $this->systemPrompt($request));
        $this->assertSame([$this->claudeAnswer], FakeWhatsAppService::$sent);
    }

    public function test_a_question_with_a_photo_that_claude_cannot_answer_is_escalated_without_learning(): void
    {
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(400, 300)];
        $this->claudeAnswer = "Je vérifie ça et je reviens vers vous très vite 😊\nJE_NE_SAIS_PAS";

        $this->receive('wamid.IMG1', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);

        $escalation = Escalation::sole();
        $this->assertSame(Escalation::REASON_IMAGE, $escalation->reason);
        $this->assertSame('📷 Photo', $escalation->customer_question);
        $this->assertTrue($escalation->hasNonTextQuestion());
    }

    // --- Preuves de paiement --------------------------------------------------------------------

    public function test_a_payment_screenshot_is_never_confirmed_and_is_attached_to_the_recent_order(): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER, 'customer_name' => 'Awa']);
        $old = $this->order($conversation, now()->subDays(10));
        $order = $this->order($conversation, now()->subHour());
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(400, 800)];
        $this->claudeAnswer = "Paiement bien reçu, merci !\nPREUVE_PAIEMENT";

        $this->receive('wamid.PAY', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);

        // Jamais la confirmation écrite par Claude : un message fixe.
        $this->assertSame(["Merci, j'ai bien reçu votre capture. Le responsable va vérifier le paiement et revient vers vous très vite."], FakeWhatsAppService::$sent);

        $photo = Message::where('whatsapp_message_id', 'wamid.PAY')->sole();
        $this->assertSame('escalated', $photo->status);
        $this->assertSame($photo->id, $order->fresh()->payment_proof_message_id);
        $this->assertNull($old->fresh()->payment_proof_message_id);

        $escalation = Escalation::sole();
        $this->assertSame(Escalation::REASON_PAYMENT_PROOF, $escalation->reason);
        $this->assertSame("Preuve de paiement reçue pour la commande {$order->reference()}", $escalation->customer_question);
        $this->assertSame($photo->id, $escalation->message_id);

        $notification = Notification::where('type', 'escalation')->sole();
        $this->assertSame('Preuve de paiement de Awa à vérifier', $notification->title);
        $this->assertSame('payment_proof', $notification->data['reason']);
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->title === 'Preuve de paiement de Awa à vérifier'
            && str_contains($mail->lines[1], "L'assistant a répondu au client"));
        Http::assertSent(fn (Request $r) => $r->url() === self::GRAPH.'CAUSEO-PHONE-ID/messages' && $r['template']['name'] === 'escalade_client');

        $this->assertSame(['order_id' => $order->id], Message::where('direction', 'outbound')->sole()->metadata['payment_proof']);
    }

    public function test_a_payment_screenshot_without_order_still_goes_to_the_owner(): void
    {
        $this->business->update(['reply_style' => ['address' => 'tu']]);
        $this->files['MEDIA-1'] = ['image/jpeg', $this->jpeg(400, 800)];
        $this->claudeAnswer = 'PREUVE_PAIEMENT';

        $this->receive('wamid.PAY', 'image', ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']);

        $this->assertSame(["Merci, j'ai bien reçu ta capture. Le responsable va vérifier le paiement et revient vers toi très vite."], FakeWhatsAppService::$sent);
        $this->assertSame('Preuve de paiement reçue', Escalation::sole()->customer_question);
    }

    // --- Documents, vidéos, autocollants ---------------------------------------------------------

    public function test_documents_and_videos_go_to_the_owner_without_a_reply(): void
    {
        $this->files['MEDIA-DOC'] = ['application/pdf', '%PDF-1.4 devis'];
        $this->files['MEDIA-VID'] = ['video/mp4', 'mp4'];

        $this->receive('wamid.DOC', 'document', ['id' => 'MEDIA-DOC', 'mime_type' => 'application/pdf', 'filename' => 'devis.pdf']);
        $this->receive('wamid.VID', 'video', ['id' => 'MEDIA-VID', 'mime_type' => 'video/mp4', 'caption' => 'Ça marche comme ça ?']);

        $document = Message::where('whatsapp_message_id', 'wamid.DOC')->sole();
        $this->assertSame('📄 Document devis.pdf', $document->content);
        $this->assertSame(['type' => 'document', 'media_id' => 'MEDIA-DOC', 'mime_type' => 'application/pdf', 'filename' => 'devis.pdf', 'size' => 14], $document->metadata);
        $this->assertSame('escalated', $document->status);

        $escalations = Escalation::orderBy('created_at')->get();
        $this->assertSame(['document_received', 'video_received'], $escalations->pluck('reason')->all());
        $this->assertSame(['Document reçu : devis.pdf', 'Vidéo reçue (« Ça marche comme ça ? »)'], $escalations->pluck('customer_question')->all());

        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame([false, false], array_column(FakeWhatsAppService::$read, 'typing'));
        $this->assertSame([], $this->claudeRequests());
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->title === 'Document de Awa à consulter'
            && str_contains($mail->lines[1], "L'assistant n'a pas répondu au client"));
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->title === 'Vidéo de Awa à regarder');
    }

    public function test_with_the_fallback_setting_the_bot_acknowledges_a_document(): void
    {
        $this->business->update(['voice_fallback_reply' => true]);
        $this->files['MEDIA-DOC'] = ['application/pdf', '%PDF-1.4'];

        $this->receive('wamid.DOC', 'document', ['id' => 'MEDIA-DOC', 'mime_type' => 'application/pdf', 'filename' => 'devis.pdf']);

        $this->assertSame(['Merci pour votre document ! Je le transmets tout de suite au responsable, qui vous répondra rapidement.'], FakeWhatsAppService::$sent);
        $this->assertSame(['escalated' => true, 'media_fallback' => 'document'], Message::where('direction', 'outbound')->sole()->metadata);
        $this->assertSame(1, app(UsageService::class)->repliesSince($this->business, now()->subDay()));
    }

    public function test_a_sticker_alone_gets_neither_a_reply_nor_an_escalation(): void
    {
        $this->receive('wamid.STK', 'sticker', ['id' => 'MEDIA-STK', 'mime_type' => 'image/webp']);

        $sticker = Message::where('direction', 'inbound')->sole();
        $this->assertSame('🙂 Autocollant', $sticker->content);
        $this->assertSame('ignored', $sticker->status);
        $this->assertSame('sticker', $sticker->metadata['type']);
        $this->assertSame(0, Escalation::count());
        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame([], $this->claudeRequests());
    }

    // --- Affichage dans le dashboard ---------------------------------------------------------------

    public function test_owner_and_admins_open_the_file_and_admin_access_is_logged(): void
    {
        $this->files['MEDIA-DOC'] = ['application/pdf', '%PDF-1.4 devis'];
        $this->receive('wamid.DOC', 'document', ['id' => 'MEDIA-DOC', 'mime_type' => 'application/pdf', 'filename' => 'Devis été.pdf']);
        $document = Message::where('direction', 'inbound')->sole();

        Sanctum::actingAs($this->owner);
        $response = $this->get("/api/v1/messages/{$document->id}/media")->assertOk();
        $this->assertSame('%PDF-1.4 devis', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Devis ete.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertSame(0, AdminAccessLog::count());

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->get("/api/v1/messages/{$document->id}/media")->assertOk();
        $this->assertSame(
            ['admin_id' => $admin->id, 'action' => 'media_viewed', 'message_id' => $document->id, 'business_id' => $this->business->id, 'conversation_id' => $document->conversation_id],
            AdminAccessLog::sole()->only(['admin_id', 'action', 'message_id', 'business_id', 'conversation_id']),
        );

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->get("/api/v1/messages/{$document->id}/media")->assertForbidden();

        // Un message sans fichier, ou un vocal (servi par /audio).
        Sanctum::actingAs($this->owner);
        $text = Conversation::sole()->messages()->create(['direction' => 'inbound', 'content' => 'Bonjour', 'status' => 'answered_by_ai']);
        $this->get("/api/v1/messages/{$text->id}/media")->assertNotFound();

        $this->mediaExpired = true;
        $this->getJson("/api/v1/messages/{$document->id}/media")
            ->assertStatus(410)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'plus disponible'));
    }

    public function test_photos_sent_by_the_owner_from_his_phone_are_recorded(): void
    {
        $this->postJson('/api/webhook/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA-1',
            'changes' => [[
                'field' => 'smb_message_echoes',
                'value' => [
                    'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                    'message_echoes' => [
                        ['from' => '22600000000', 'to' => self::CUSTOMER, 'id' => 'wamid.ECHO1', 'type' => 'image', 'image' => ['id' => 'MEDIA-E1', 'mime_type' => 'image/jpeg', 'caption' => 'La voici en 42']],
                        ['from' => '22600000000', 'to' => self::CUSTOMER, 'id' => 'wamid.ECHO2', 'type' => 'document', 'document' => ['id' => 'MEDIA-E2', 'mime_type' => 'application/pdf', 'filename' => 'facture.pdf']],
                    ],
                ],
            ]],
        ]]])->assertOk();

        $echoes = Message::orderBy('created_at')->orderBy('whatsapp_message_id')->get();
        $this->assertSame(['La voici en 42', '📄 Document facture.pdf'], $echoes->pluck('content')->all());
        $this->assertSame(['human', 'human'], $echoes->pluck('sender_type')->all());
        $this->assertSame(['type' => 'image', 'media_id' => 'MEDIA-E1', 'mime_type' => 'image/jpeg', 'caption' => 'La voici en 42'], $echoes[0]->metadata);
        $this->assertSame('facture.pdf', $echoes[1]->metadata['filename']);
        // Ni exemple de ton, ni réponse automatique.
        $this->assertSame(0, ManagerReply::count());
        $this->assertSame([], $this->claudeRequests());
    }

    // --- Photos envoyées par l'assistant ----------------------------------------------------------

    public function test_the_same_photo_is_sent_only_once_in_a_reply(): void
    {
        [$robe, $copy, $shirt] = $this->sendableMedia();
        $this->claudeAnswer = "Voici la robe wax Awa et la chemise.\nMEDIA:{$robe},{$copy},{$robe},{$shirt}";

        $this->receiveRaw(['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Montrez-moi la robe wax et la chemise']]);

        // La copie (même fichier téléversé deux fois) et l'identifiant répété ne partent pas.
        $this->assertSame(['https://cdn.test/robe.jpg', 'https://cdn.test/chemise.jpg'], FakeWhatsAppService::$images);
        $this->assertSame(['Voici la robe wax Awa et la chemise.'], FakeWhatsAppService::$sent);
        $this->assertSame([
            ['media_id' => $robe, 'whatsapp_message_id' => 'wamid.IMG-OUT1'],
            ['media_id' => $shirt, 'whatsapp_message_id' => 'wamid.IMG-OUT2'],
        ], Message::where('direction', 'outbound')->sole()->metadata['sent_media']);
    }

    public function test_two_photos_of_the_same_catalog_article_are_sent_once(): void
    {
        $this->business->update(['modules' => ['orders', 'catalog']]);
        [$robe, , $shirt] = $this->sendableMedia();
        app(\App\Services\Catalog\CatalogService::class)->saveProduct($this->business->fresh(), ['name' => 'Chemise en lin', 'base_price' => 9000, 'media_ids' => [$robe, $shirt]], null, [['options' => ['taille' => 'L']]]);
        $this->claudeAnswer = "La voici.\nMEDIA:{$robe},{$shirt}";

        $this->receiveRaw(['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Une photo de la chemise ?']]);

        $this->assertSame(['https://cdn.test/robe.jpg'], FakeWhatsAppService::$images);
    }

    // --- Réponses citées -------------------------------------------------------------------------

    public function test_a_reply_quoting_a_photo_sent_by_the_assistant_names_the_catalog_article(): void
    {
        $this->business->update(['modules' => ['orders', 'catalog']]);
        [$robe] = $this->sendableMedia();
        app(\App\Services\Catalog\CatalogService::class)->saveProduct($this->business->fresh(), ['name' => 'Robe de soiree', 'base_price' => 15000, 'media_ids' => [$robe]], null, [['options' => ['taille' => 'M']]]);
        $this->claudeAnswer = "La voici.\nMEDIA:{$robe}";
        $this->receiveRaw(['id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Une photo de la robe de soirée ?']]);
        $this->assertSame(['https://cdn.test/robe.jpg'], FakeWhatsAppService::$images);

        $this->travel(1)->minutes();
        $this->claudeAnswer = 'Elle est à 15 000 FCFA.';
        $this->receiveRaw([
            'id' => 'wamid.2', 'type' => 'text', 'text' => ['body' => 'Combien ?'],
            'context' => ['from' => '22625000000', 'id' => 'wamid.IMG-OUT1'],
        ]);

        $inbound = Message::where('whatsapp_message_id', 'wamid.2')->sole();
        $this->assertSame(['reply_to' => 'wamid.IMG-OUT1'], $inbound->metadata);

        $request = last($this->claudeRequests());
        $userMessage = end($request['messages'])['content'];
        $this->assertStringContainsString("Le client répond à la photo « Robe wax Awa » que tu lui as envoyée, qui montre l'article du catalogue « Robe de soiree ».\n\nQuestion du client : Combien ?", $userMessage);
        // Le nom de l'article guide la recherche dans le catalogue.
        $this->assertStringContainsString('"name":"Robe de soiree"', $userMessage);
        $this->assertStringContainsString('# Message cité', $this->systemPrompt($request));
        $this->assertSame('Elle est à 15 000 FCFA.', last(FakeWhatsAppService::$sent));
    }

    public function test_a_reply_quoting_an_assistant_message_or_a_customer_photo_is_described(): void
    {
        $conversation = Conversation::create(['business_id' => $this->business->id, 'customer_phone' => self::CUSTOMER]);
        $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'content' => MediaMessage::PLACEHOLDERS['image'], 'status' => 'answered_by_ai',
            'whatsapp_message_id' => 'wamid.PHOTO', 'metadata' => ['type' => 'image', 'media_id' => 'MEDIA-7', 'mime_type' => 'image/jpeg'],
        ]);
        $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'content' => 'La robe wax Awa existe en 38, 40 et 42.', 'status' => 'sent', 'whatsapp_message_id' => 'wamid.OUT9',
        ]);
        $this->files['MEDIA-7'] = ['image/jpeg', $this->jpeg(200, 200)];

        $this->receiveRaw(['id' => 'wamid.3', 'type' => 'text', 'text' => ['body' => 'Je prends la 40'], 'context' => ['from' => '22625000000', 'id' => 'wamid.OUT9']]);
        $this->travel(1)->minutes();
        $this->receiveRaw(['id' => 'wamid.4', 'type' => 'text', 'text' => ['body' => 'Et celle-ci ?'], 'context' => ['from' => self::CUSTOMER, 'id' => 'wamid.PHOTO']]);

        [$first, $second] = $this->claudeRequests();
        $this->assertStringContainsString('Le client répond à ce message que tu lui as envoyé : « La robe wax Awa existe en 38, 40 et 42. ».', end($first['messages'])['content']);

        // La photo citée du client est de nouveau montrée à l'assistant.
        [$image, $text] = end($second['messages'])['content'];
        $this->assertSame('image', $image['type']);
        $this->assertStringContainsString("Le client cite une photo qu'il a envoyée (cette photo est jointe au message).", $text['text']);
    }

    public function test_a_reply_to_a_status_asks_which_article_and_logs_the_payload_without_numbers(): void
    {
        Log::spy();

        $this->receiveRaw([
            'id' => 'wamid.5', 'type' => 'text', 'text' => ['body' => 'Il est à combien ? Appelez-moi au 70 11 22 33'],
            'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-1'],
        ]);

        $userMessage = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertStringContainsString('Le client répond à un message que tu ne vois pas (probablement un statut WhatsApp de la boutique', $userMessage);
        $this->assertStringContainsString("pose une seule question, « Pouvez-vous m'envoyer une capture de l'article ? », puis ajoute PRECISION", $userMessage);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            if (! str_contains($message, 'contenu inconnu')) {
                return false;
            }

            $payload = json_encode($context['payload']);
            $this->assertSame(['message_cite_introuvable'], $context['reasons']);
            $this->assertSame('wamid.STATUS-1', $context['payload']['context']['id']);
            $this->assertStringNotContainsString(self::CUSTOMER, $payload);
            $this->assertStringNotContainsString('22625000000', $payload);
            $this->assertStringNotContainsString('70 11 22 33', $payload);

            return true;
        })->once();
    }

    public function test_a_message_from_a_post_or_an_ad_uses_its_text_and_image(): void
    {
        $this->receiveRaw([
            'id' => 'wamid.6', 'type' => 'text', 'text' => ['body' => 'Bonjour, je suis intéressée'],
            'referral' => [
                'source_type' => 'post', 'source_url' => 'https://fb.me/abc', 'headline' => 'Nouvelle collection', 'body' => 'Robe wax Awa, 12 500 FCFA',
                'media_type' => 'image', 'image_url' => 'https://scontent.xx.fbcdn.net/v/robe.jpg',
            ],
        ]);

        $this->assertSame('post', Message::where('whatsapp_message_id', 'wamid.6')->sole()->metadata['referral']['source_type']);

        [$image, $text] = end($this->claudeRequests()[0]['messages'])['content'];
        $this->assertSame('image', $image['type']);
        $this->assertStringContainsString("Le client écrit depuis une publication ou une publicité de la boutique (titre « Nouvelle collection », texte « Robe wax Awa, 12 500 FCFA »). L'image de cette publication est jointe au message.", $text['text']);
    }

    public function test_an_image_outside_meta_servers_is_never_downloaded(): void
    {
        $this->receiveRaw([
            'id' => 'wamid.7', 'type' => 'text', 'text' => ['body' => 'Bonjour'],
            'referral' => ['source_type' => 'ad', 'headline' => 'Promo', 'image_url' => 'https://evil.test/x.jpg'],
        ]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.test'));
        $this->assertIsString(end($this->claudeRequests()[0]['messages'])['content']);
    }

    // --- Outils -------------------------------------------------------------------------------------

    /**
     * Médias envoyables : la robe, sa copie (même fichier téléversé deux fois) et une chemise.
     * L'assistant les voit tous comme pertinents.
     *
     * @return list<string>
     */
    private function sendableMedia(): array
    {
        DB::table('business_media')->update(['public_url' => 'https://cdn.test/robe.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1000]);
        $robe = DB::table('business_media')->value('id');
        $ids = [$robe];

        foreach ([['Robe wax Awa (copie)', 'robe-2.jpg', 1000], ['Chemise en lin', 'chemise.jpg', 2000]] as [$title, $file, $size]) {
            $ids[] = $id = (string) Str::uuid();
            DB::table('business_media')->insert([
                'id' => $id, 'business_id' => $this->business->id, 'type' => 'image', 'title' => $title, 'file_path' => $file,
                'public_url' => "https://cdn.test/{$file}", 'mime_type' => 'image/jpeg', 'file_size' => $size, 'keywords' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->app->instance(AIResponseService::class, new class(self::CONTEXT) extends FixedContextAIResponseService
        {
            public function findRelevantMedia(Business $business, string $question, int $limit = 3): \Illuminate\Support\Collection
            {
                return $business->media()->active()->orderBy('created_at')->get();
            }
        });

        return $ids;
    }

    /**
     * Message entrant tel que l'envoie Meta (texte, contexte de citation, referral…).
     *
     * @param  array<string, mixed>  $message
     */
    private function receiveRaw(array $message): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                        'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => self::CUSTOMER]],
                        'messages' => [['from' => self::CUSTOMER] + $message],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function receive(string $messageId, string $type, array $media): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-JOYCE'],
                        'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => self::CUSTOMER]],
                        'messages' => [['from' => self::CUSTOMER, 'id' => $messageId, 'type' => $type, $type => $media]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claudeRequests(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function order(Conversation $conversation, \DateTimeInterface $at): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id,
            'conversation_id' => $conversation->id,
            'customer_phone' => self::CUSTOMER,
            'customer_name' => 'Awa',
            'items' => [['name' => 'Robe wax Awa', 'quantity' => 1, 'unit_price' => 12500]],
            'total_amount' => 12500,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'Orange Money',
            'status' => 'new',
        ]);
        $order->forceFill(['created_at' => $at])->save();

        return $order;
    }

    private function png(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    private function jpeg(int $width, int $height): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }
}
