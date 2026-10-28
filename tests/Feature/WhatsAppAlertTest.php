<?php

namespace Tests\Feature;

use App\Console\Commands\RegisterNotifyNumber;
use App\Jobs\SendOwnerWhatsAppAlert;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Escalation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WhatsApp\CauseoNotifyClient;
use App\Services\WhatsApp\OwnerAlertService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class WhatsAppAlertTest extends TestCase
{
    use CreatesConversationSchema;

    private const GRAPH = 'https://graph.facebook.com/v21.0/';

    private const OWNER_PHONE = '+22670112233';

    private const CUSTOMER_PHONE = '22675556677';

    private const TEMPLATE_REFUSED = [
        'error' => [
            'message' => '(#132001) Template name does not exist in the translation',
            'type' => 'OAuthException',
            'code' => 132001,
            'error_data' => ['messaging_product' => 'whatsapp', 'details' => 'template name (escalade_client) does not exist in fr'],
        ],
    ];

    private User $owner;

    private Business $business;

    private string $conversationId;

    /** @var list<string> */
    private array $logged = [];

    /** Réponse de Meta aux appels suivants : [corps, code HTTP]. */
    private array $metaResponse = [['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.ALERT']]], 200];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-12 09:00:00');
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_100000_add_whatsapp_alerts_to_users.php'))->up();

        config([
            'services.whatsapp.token' => 'system-token',
            'services.whatsapp.api_url' => self::GRAPH,
            'services.causeo_notify.phone_number_id' => 'CAUSEO-PHONE-ID',
            'services.causeo_notify.waba_id' => 'CAUSEO-WABA-ID',
        ]);
        Http::fake([self::GRAPH.'*' => fn () => Http::response(...$this->metaResponse)]);
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->level.' '.$event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });

        $this->owner = User::forceCreate([
            'name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x',
            'notification_phone' => self::OWNER_PHONE,
            'whatsapp_alerts_enabled' => true,
            'whatsapp_alerts_consented_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]']);
        $this->business = Business::findOrFail($id);
        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $this->conversationId, 'business_id' => $id, 'customer_phone' => self::CUSTOMER_PHONE, 'customer_name' => 'Awa']);
    }

    // --- Conditions d'envoi -----------------------------------------------------------

    public function test_escalation_sends_the_approved_template_to_the_owner(): void
    {
        $question = "Bonjour,\nlivrez-vous à Koudougou ?\t\tJe voudrais aussi savoir si vous avez le pagne wax bleu en 6 yards, et à quel prix exactement.";
        $this->escalate($question);

        $this->assertSame(1, Notification::where('type', 'escalation')->count());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $parameters = $request['template']['components'][0]['parameters'];
            $text = $parameters[1]['text'];

            return $request->url() === self::GRAPH.'CAUSEO-PHONE-ID/messages'
                && $request->hasHeader('Authorization', 'Bearer system-token')
                && $request['to'] === '22670112233'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'escalade_client'
                && $request['template']['language'] === ['code' => 'fr']
                && $parameters[0] === ['type' => 'text', 'text' => 'Joyce Boutique']
                && mb_strlen($text) === OwnerAlertService::QUESTION_MAX_LENGTH
                && str_starts_with($text, 'Bonjour, livrez-vous à Koudougou ? Je voudrais')
                && str_ends_with($text, '…')
                && ! preg_match('/[\n\t]/', $text);
        });
        $this->assertLogsHideCustomerData();
    }

    public function test_nothing_is_sent_when_the_option_is_disabled(): void
    {
        $this->owner->update(['whatsapp_alerts_enabled' => false]);

        $this->escalate('Livrez-vous à Koudougou ?');
        $this->notifyOrder();

        Http::assertNothingSent();
        $this->assertSame(2, Notification::count());
    }

    public function test_nothing_is_sent_without_explicit_consent(): void
    {
        $this->owner->update(['whatsapp_alerts_consented_at' => null]);

        $this->escalate('Livrez-vous à Koudougou ?');
        $this->notifyOrder();

        Http::assertNothingSent();
        $this->assertSame(2, Notification::count());
    }

    public function test_nothing_is_sent_while_the_causeo_number_is_not_configured(): void
    {
        config(['services.causeo_notify.phone_number_id' => null]);

        $this->escalate('Livrez-vous à Koudougou ?');

        Http::assertNothingSent();
        $this->assertSame(1, Notification::count());
    }

    // --- Anti-inondation ----------------------------------------------------------------

    public function test_escalation_alerts_are_limited_to_one_per_business_every_15_minutes(): void
    {
        $this->escalate('Livrez-vous à Koudougou ?');
        $this->travel(5)->minutes();
        $this->escalate('Et à Kaya ?', $this->newConversation());
        $this->travel(9)->minutes();
        $this->escalate('Vous ouvrez dimanche ?', $this->newConversation());

        // Une seule alerte WhatsApp, mais les trois questions sont dans la cloche.
        Http::assertSentCount(1);
        $this->assertSame(3, Notification::where('type', 'escalation')->count());

        $this->travel(2)->minutes();
        $this->escalate('Le pagne bleu est disponible ?', $this->newConversation());
        Http::assertSentCount(2);

        // Un autre business a sa propre période.
        $other = $this->newBusiness();
        $this->escalate('Bonjour ?', $this->newConversation($other->id), $other->id);
        Http::assertSentCount(3);
    }

    public function test_orders_and_appointments_are_always_sent(): void
    {
        $this->escalate('Livrez-vous à Koudougou ?');
        $this->notifyOrder();
        $this->notifyOrder();
        $this->notifyAppointment('15:00');
        $this->notifyAppointment('après-midi');

        Http::assertSentCount(5);

        $sent = Http::recorded()->map(fn (array $pair) => [
            $pair[0]['template']['name'],
            array_column($pair[0]['template']['components'][0]['parameters'], 'text'),
        ])->all();

        $this->assertSame(['nouvelle_commande', ['Joyce Boutique', '2 × Pagne wax bleu, 1 × Sac en raphia', '26 000 FCFA']], $sent[1]);
        $this->assertSame(['nouveau_rendez_vous', ['Joyce Boutique', 'Tresses africaines', 'samedi 17 octobre à 15h']], $sent[3]);
        $this->assertSame(['nouveau_rendez_vous', ['Joyce Boutique', 'Tresses africaines', 'samedi 17 octobre (après-midi)']], $sent[4]);
    }

    // --- Erreurs de Meta ------------------------------------------------------------------

    public function test_a_template_refused_by_meta_is_logged_without_breaking_anything(): void
    {
        $this->metaResponse = [self::TEMPLATE_REFUSED, 400];

        $escalation = $this->escalate('Livrez-vous à Koudougou ?');

        $this->assertNotNull($escalation->fresh());
        $this->assertSame(1, Notification::where('type', 'escalation')->count());
        Http::assertSentCount(1);

        $warnings = array_values(array_filter($this->logged, fn (string $line) => str_starts_with($line, 'warning')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('refusée par Meta', $warnings[0]);
        $this->assertStringContainsString('"error_code":132001', $warnings[0]);
        $this->assertStringContainsString('"template":"escalade_client"', $warnings[0]);
        $this->assertLogsHideCustomerData();
    }

    public function test_a_temporary_meta_error_is_retried_by_the_queue(): void
    {
        $this->metaResponse = [['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503];
        $escalation = Escalation::withoutEvents(fn () => Escalation::create([
            'conversation_id' => $this->conversationId, 'business_id' => $this->business->id, 'customer_question' => 'Livrez-vous ?',
        ]));

        $job = new SendOwnerWhatsAppAlert(OwnerAlertService::ESCALATION, $escalation->id, $this->business->id);
        $this->assertSame(3, $job->tries);

        try {
            $job->handle(app(OwnerAlertService::class));
            $this->fail('Une erreur passagère doit relancer le job.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
            $this->assertStringNotContainsString('22670112233', $e->getMessage());
        }
    }

    public function test_the_job_rechecks_the_owner_choice_before_sending(): void
    {
        $escalation = Escalation::withoutEvents(fn () => Escalation::create([
            'conversation_id' => $this->conversationId, 'business_id' => $this->business->id, 'customer_question' => 'Livrez-vous ?',
        ]));
        $this->owner->update(['whatsapp_alerts_enabled' => false]);

        (new SendOwnerWhatsAppAlert(OwnerAlertService::ESCALATION, $escalation->id, $this->business->id))->handle(app(OwnerAlertService::class));

        Http::assertNothingSent();
    }

    // --- Réglages du gérant ---------------------------------------------------------------

    public function test_owner_reads_and_updates_alert_settings(): void
    {
        $owner = User::forceCreate(['name' => 'Fatou', 'email' => 'fatou@example.test', 'password' => 'x']);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me/whatsapp-alerts')->assertOk()->assertExactJson([
            'notification_phone' => null,
            'whatsapp_alerts_enabled' => false,
            'whatsapp_alerts_consented_at' => null,
            'alerts_active' => false,
            'service_available' => true,
        ]);

        // Activer sans consentement : refusé.
        $this->putJson('/api/v1/me/whatsapp-alerts', ['notification_phone' => '00226 70 11 22 33', 'whatsapp_alerts_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('whatsapp_alerts_enabled');
        $this->assertFalse($owner->fresh()->whatsapp_alerts_enabled);

        $this->putJson('/api/v1/me/whatsapp-alerts', ['notification_phone' => '+226 70 11 22 33', 'whatsapp_alerts_enabled' => true, 'consent' => true])
            ->assertOk()
            ->assertJsonPath('notification_phone', '+22670112233')
            ->assertJsonPath('whatsapp_alerts_enabled', true)
            ->assertJsonPath('whatsapp_alerts_consented_at', now()->toIso8601String())
            ->assertJsonPath('alerts_active', true);

        // Le consentement porte sur un numéro : en changer le retire et coupe les alertes.
        $this->putJson('/api/v1/me/whatsapp-alerts', ['notification_phone' => '+22676000000'])
            ->assertOk()
            ->assertJsonPath('whatsapp_alerts_consented_at', null)
            ->assertJsonPath('whatsapp_alerts_enabled', false)
            ->assertJsonPath('alerts_active', false);

        // Retirer son consentement coupe aussi les alertes.
        $this->putJson('/api/v1/me/whatsapp-alerts', ['consent' => true, 'whatsapp_alerts_enabled' => true])->assertJsonPath('alerts_active', true);
        $this->putJson('/api/v1/me/whatsapp-alerts', ['consent' => false])
            ->assertJsonPath('whatsapp_alerts_enabled', false)
            ->assertJsonPath('alerts_active', false);

        $this->putJson('/api/v1/me/whatsapp-alerts', ['notification_phone' => '70112233'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notification_phone' => 'format international']);
    }

    public function test_test_alert_returns_the_meta_response(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/me/whatsapp-alerts/test')
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('template', 'escalade_client')
            ->assertJsonPath('parameters', ['Joyce Boutique', 'Bonjour, livrez-vous à Koudougou demain ?'])
            ->assertJsonPath('meta.messages.0.id', 'wamid.ALERT');

        Http::assertSent(fn (Request $request) => $request['to'] === '22670112233' && $request['template']['name'] === 'escalade_client');
    }

    public function test_test_alert_returns_the_exact_meta_error(): void
    {
        $this->metaResponse = [self::TEMPLATE_REFUSED, 400];
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/me/whatsapp-alerts/test')
            ->assertStatus(502)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('status', 400)
            ->assertJsonPath('error', self::TEMPLATE_REFUSED['error']);
    }

    public function test_test_alert_requires_a_number_with_consent_and_a_configured_service(): void
    {
        $this->owner->update(['whatsapp_alerts_consented_at' => null]);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/me/whatsapp-alerts/test')->assertUnprocessable();

        $this->owner->update(['whatsapp_alerts_consented_at' => now()]);
        config(['services.causeo_notify.phone_number_id' => '']);
        $this->postJson('/api/v1/me/whatsapp-alerts/test')->assertStatus(503);

        Http::assertNothingSent();
    }

    // --- Enregistrement du numéro -----------------------------------------------------------

    public function test_register_command_calls_the_register_endpoint(): void
    {
        $this->metaResponse = [['success' => true, 'data' => [['whatsapp_business_api_data' => ['id' => 'APP-ID', 'name' => 'Causeo']]]], 200];

        $this->artisan('whatsapp:register-notify-number', ['--pin' => '123456'])
            ->expectsOutputToContain('Apps abonnées : Causeo')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::GRAPH.'CAUSEO-PHONE-ID/register'
            && $request->data() === ['messaging_product' => 'whatsapp', 'pin' => '123456']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === self::GRAPH.'CAUSEO-WABA-ID/subscribed_apps');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::GRAPH.'CAUSEO-WABA-ID/subscribed_apps');
        Http::assertSentCount(3);

        $this->artisan('whatsapp:register-notify-number', ['--pin' => '12345'])->assertFailed();
        Http::assertSentCount(3);
    }

    public function test_register_command_without_pin_only_subscribes_the_waba(): void
    {
        $this->metaResponse = [['success' => true, 'data' => [['whatsapp_business_api_data' => ['id' => 'APP-ID', 'name' => 'Causeo']]]], 200];

        $this->artisan('whatsapp:register-notify-number')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/register'));
    }

    public function test_register_command_fails_when_no_app_is_subscribed(): void
    {
        $this->metaResponse = [['success' => true, 'data' => []], 200];

        $this->artisan('whatsapp:register-notify-number')
            ->expectsOutputToContain('aucune app abonnée')
            ->assertFailed();
    }

    public function test_register_command_requires_the_waba_id(): void
    {
        config(['services.causeo_notify.waba_id' => '']);

        $this->artisan('whatsapp:register-notify-number')
            ->expectsOutputToContain('CAUSEO_NOTIFY_WABA_ID')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_register_command_waits_sixty_seconds_for_meta(): void
    {
        $this->assertSame(60, RegisterNotifyNumber::TIMEOUT);

        $client = (new CauseoNotifyClient)->withTimeout(RegisterNotifyNumber::TIMEOUT);
        $timeout = (new \ReflectionProperty(CauseoNotifyClient::class, 'timeout'))->getValue($client);

        $this->assertSame(60, $timeout);
        $this->assertSame(15, (new \ReflectionProperty(CauseoNotifyClient::class, 'timeout'))->getValue(new CauseoNotifyClient));
    }

    // --- Statuts des alertes (webhook) ---------------------------------------------------------

    public function test_failed_alert_status_is_logged_and_notifies_the_admins(): void
    {
        $this->escalate('Livrez-vous à Koudougou ?');
        $this->logged = [];

        $payload = $this->statusPayload('failed', [[
            'code' => 131026,
            'title' => 'Message undeliverable',
            'message' => 'Message undeliverable',
            'error_data' => ['details' => 'Le destinataire +226 70 11 22 33 n\'utilise pas WhatsApp.'],
        ]]);

        $this->postJson('/api/webhook/whatsapp', $payload)->assertOk();
        $this->postJson('/api/webhook/whatsapp', $payload)->assertOk();

        $notification = Notification::where('audience', Notification::ADMIN)->sole();
        $this->assertSame('whatsapp', $notification->type);
        $this->assertSame($this->business->id, $notification->business_id);
        $this->assertSame('Alerte WhatsApp non livrée (131026)', $notification->title);
        $this->assertStringContainsString('escalade_client', $notification->body);
        $this->assertStringContainsString('[numéro masqué]', $notification->body);
        $this->assertSame('whatsapp_alert_failed', $notification->data['kind']);
        $this->assertSame(131026, $notification->data['error_code']);
        $this->assertSame('Message undeliverable', $notification->data['error_message']);

        $logs = implode("\n", $this->logged);
        $this->assertStringContainsString('non livrée par Meta', $logs);
        $this->assertStringContainsString('131026', $logs);
        $this->assertLogsHideCustomerData();
    }

    public function test_other_alert_statuses_are_only_logged(): void
    {
        $this->escalate('Livrez-vous à Koudougou ?');
        $this->logged = [];

        foreach (['sent', 'delivered', 'read'] as $state) {
            $this->postJson('/api/webhook/whatsapp', $this->statusPayload($state))->assertOk();
            $this->assertStringContainsString("statut {$state}", implode("\n", $this->logged));
        }

        $this->assertSame(0, Notification::where('audience', Notification::ADMIN)->count());
        $this->assertLogsHideCustomerData();
    }

    public function test_failed_status_of_an_unknown_message_does_not_notify(): void
    {

        $this->postJson('/api/webhook/whatsapp', $this->statusPayload('failed', [['code' => 131026, 'message' => 'Message undeliverable']], 'wamid.UNKNOWN'))->assertOk();

        $this->assertSame(0, Notification::where('audience', Notification::ADMIN)->count());
        $this->assertStringContainsString('non livrée par Meta', implode("\n", $this->logged));
    }

    // --- Outils -------------------------------------------------------------------------------

    private function escalate(string $question, ?string $conversationId = null, ?string $businessId = null): Escalation
    {
        return Escalation::create([
            'conversation_id' => $conversationId ?? $this->conversationId,
            'business_id' => $businessId ?? $this->business->id,
            'customer_question' => $question,
        ]);
    }

    private function notifyOrder(): void
    {
        app(NotificationService::class)->notifyOrder(Order::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => self::CUSTOMER_PHONE,
            'customer_name' => 'Awa',
            'items' => [['name' => 'Pagne wax bleu', 'quantity' => 2, 'unit_price' => 10000], ['name' => 'Sac en raphia', 'quantity' => 1, 'unit_price' => 6000]],
            'total_amount' => 26000,
        ]));
    }

    private function notifyAppointment(string $time): void
    {
        app(NotificationService::class)->notifyAppointment(Appointment::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => self::CUSTOMER_PHONE,
            'customer_name' => 'Awa',
            'service' => 'Tresses africaines',
            'requested_date' => '2026-10-17',
            'requested_time' => $time,
        ]));
    }

    /**
     * Webhook de statut de Meta pour un message envoyé depuis le numéro Causeo.
     *
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    private function statusPayload(string $state, array $errors = [], string $messageId = 'wamid.ALERT'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'CAUSEO-WABA-ID',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '22660000000', 'phone_number_id' => 'CAUSEO-PHONE-ID'],
                    'statuses' => [array_filter([
                        'id' => $messageId,
                        'status' => $state,
                        'timestamp' => '1760259600',
                        'recipient_id' => '22670112233',
                        'errors' => $errors ?: null,
                    ])],
                ],
            ]],
        ]]];
    }

    private function newConversation(?string $businessId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $businessId ?? $this->business->id, 'customer_phone' => self::CUSTOMER_PHONE]);

        return $id;
    }

    private function newBusiness(): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Coiffure', 'modules' => '[]']);

        return Business::findOrFail($id);
    }

    private function assertLogsHideCustomerData(): void
    {
        $logs = implode("\n", $this->logged);

        foreach (['22670112233', self::CUSTOMER_PHONE, 'Koudougou', 'Awa'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }
}
