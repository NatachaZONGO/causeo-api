<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\AppointmentService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use CreatesConversationSchema;

    private const CONTEXT = 'Coupe femme : 5 000 FCFA. Tresses : 15 000 FCFA. Ouvert du mardi au samedi de 9h à 19h.';

    private User $owner;

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        $this->travelTo('2026-10-08 10:00:00');

        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);

        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $businessId,
            'user_id' => $this->owner->id,
            'name' => 'Belle Coiffure',
            'type' => 'salon_beaute',
            'modules' => '["appointments"]',
        ]);
        $this->business = Business::findOrFail($businessId);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $this->conversationId,
            'business_id' => $businessId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
        ]);
    }

    public function test_confirmed_request_is_recorded_through_the_tool(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUseResponse($this->validInput() + ['location' => 'À domicile, Ouaga 2000', 'participants' => 2]))
            ->push($this->textResponse('Votre demande de rendez-vous est enregistrée ✅ Le créneau vous sera confirmé très vite.')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Votre demande de rendez-vous est enregistrée ✅ Le créneau vous sera confirmé très vite.', $result['answer']);
        $this->assertFalse($result['should_escalate']);

        $appointment = Appointment::sole();
        $this->assertSame('Tresses', $appointment->service);
        $this->assertSame('2026-10-10', $appointment->requested_date->format('Y-m-d'));
        $this->assertSame('15h', $appointment->requested_time);
        $this->assertSame('Awa Ouédraogo', $appointment->customer_name);
        $this->assertSame('22670000001', $appointment->customer_phone);
        $this->assertSame('À domicile, Ouaga 2000', $appointment->location);
        $this->assertSame(2, $appointment->participants);
        $this->assertSame(15000.0, $appointment->price);
        $this->assertSame('requested', $appointment->status);
        $this->assertSame([['id' => $appointment->id, 'reference' => $appointment->reference()]], $result['tool_trace']['appointments']);

        $notification = Notification::sole();
        $this->assertSame('appointment', $notification->type);
        $this->assertSame('Demande de rendez-vous de Awa Ouédraogo', $notification->title);
        $this->assertSame('Tresses — 10/10/2026 à 15h, À domicile, Ouaga 2000, 2 participants, 15 000 FCFA', $notification->body);
        $this->assertSame(['conversation_id' => $this->conversationId, 'appointment_id' => $appointment->id], $notification->data);

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data());
        $this->assertSame(['create_appointment'], array_column($requests[0]['tools'], 'name'));
        $this->assertStringContainsString('# Prise de rendez-vous', $requests[0]['system']);
        $this->assertStringContainsString('Nous sommes le jeudi 8 octobre 2026.', $requests[0]['system']);
        $this->assertStringNotContainsString('# Prise de commande', $requests[0]['system']);

        $toolResult = json_decode($requests[1]['messages'][4]['content'][0]['content'], true);
        $this->assertSame($appointment->reference(), $toolResult['reference']);
        $this->assertSame('2026-10-10', $toolResult['requested_date']);
        $this->assertStringContainsString('créneau à confirmer', $toolResult['status']);
    }

    public function test_restaurant_with_both_modules_gets_both_tools(): void
    {
        $this->business->update(['modules' => ['orders', 'appointments']]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Avec plaisir !'))]);

        $this->service()->answer($this->business, 'Je voudrais réserver une table', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertSame(['create_order', 'create_appointment'], array_column($request['tools'], 'name'));
        $this->assertStringContainsString('# Prise de commande', $request['system']);
        $this->assertStringContainsString('# Prise de rendez-vous', $request['system']);
    }

    public function test_tool_and_prompt_are_not_offered_without_the_module(): void
    {
        $this->seedRecapConversation();
        $this->business->update(['modules' => []]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Je vérifie ça et je reviens vers vous très vite 😊'))]);

        $this->service()->answer($this->business, 'oui', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertArrayNotHasKey('tools', $request);
        $this->assertStringNotContainsString('# Prise de rendez-vous', $request['system']);
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $this->assertRejected(['service' => null], 'Informations manquantes : service');
        $this->assertRejected(['requested_date' => null, 'requested_time' => ' '], 'Informations manquantes : requested_date, requested_time');
        $this->assertRejected(['requested_date' => '10/10/2026'], 'Date invalide');
        $this->assertRejected(['requested_date' => '2026-02-30'], 'Date invalide');
        $this->assertRejected(['requested_date' => '2026-10-07'], 'est déjà passée');
        $this->assertRejected(['participants' => 0], 'participants');
        $this->assertRejected(['participants' => 1.5], 'participants');
        $this->assertRejected(['price' => -5], 'prix est invalide');

        $this->conversation()->update(['customer_name' => null]);
        $this->assertRejected(['customer_name' => null], 'Informations manquantes : customer_name');

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_today_is_accepted_and_optional_fields_can_be_empty(): void
    {
        $appointment = app(AppointmentService::class)->createFromAi($this->business, $this->conversation(), [
            'service' => 'Coupe femme',
            'requested_date' => '2026-10-08',
            'requested_time' => 'après-midi',
            'customer_name' => 'Awa',
            'participants' => '',
            'price' => null,
        ]);

        $this->assertNull($appointment->participants);
        $this->assertNull($appointment->price);
        $this->assertNull($appointment->location);
        $this->assertSame('Coupe femme — 08/10/2026 à après-midi', Notification::sole()->body);
    }

    public function test_identical_confirmation_does_not_duplicate_the_request(): void
    {
        $first = app(AppointmentService::class)->createFromAi($this->business, $this->conversation(), $this->validInput());
        $second = app(AppointmentService::class)->createFromAi($this->business, $this->conversation(), ['service' => 'tresses'] + $this->validInput());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, Notification::count());
    }

    public function test_booked_claim_is_blocked_even_after_the_tool_and_retry_succeeds(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUseResponse($this->validInput()))
            ->push($this->textResponse('Parfait, votre rendez-vous est confirmé pour samedi à 15h !'))
            ->push($this->textResponse('Votre demande est bien enregistrée. Le créneau vous sera confirmé très vite 😊')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Votre demande est bien enregistrée. Le créneau vous sera confirmé très vite 😊', $result['answer']);
        $this->assertFalse($result['should_escalate']);
        $this->assertSame(1, Appointment::count());
        Http::assertSentCount(3);
        $this->assertStringContainsString(
            "rendez-vous annoncé comme réservé ou confirmé alors que seule l'entreprise peut le confirmer",
            Http::recorded()[2][0]->data()['messages'][6]['content'],
        );
    }

    public function test_recorded_claim_without_the_tool_escalates_after_a_second_failure(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse('Votre demande de rendez-vous est enregistrée !'))
            ->push($this->textResponse('Votre séance est réservée pour samedi.')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Je vérifie ça et je reviens vers vous très vite 😊', $result['answer']);
        $this->assertTrue($result['should_escalate']);
        $this->assertTrue($result['tool_trace']['blocked_claim']);
        $this->assertSame(0, Appointment::count());
        $this->assertStringContainsString(
            'demande de rendez-vous annoncée comme enregistrée sans appel réussi à create_appointment',
            Http::recorded()[1][0]->data()['messages'][4]['content'],
        );
    }

    public function test_booked_claim_is_allowed_once_the_business_confirmed(): void
    {
        $this->makeAppointment('confirmed');
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Oui, votre rendez-vous de samedi est bien confirmé 😊'))]);

        $result = $this->service()->answer($this->business, 'Mon rendez-vous est confirmé ?', $this->conversationId);

        $this->assertSame('Oui, votre rendez-vous de samedi est bien confirmé 😊', $result['answer']);
        Http::assertSentCount(1);
    }

    public function test_recap_question_and_future_confirmation_are_allowed(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse("Tresses, samedi 10 octobre à 15h, 15 000 FCFA. Je confirme le rendez-vous ?\nLe créneau sera confirmé par le salon."))
            ->push($this->textResponse('Je vous confirme le rendez-vous de samedi.'))
            ->push($this->textResponse('Très bien, je note.')),
        ]);

        $recap = $this->service()->answer($this->business, 'Je veux des tresses samedi à 15h', $this->conversationId);
        $blocked = $this->service()->answer($this->business, 'Je veux des tresses samedi à 15h', $this->conversationId);

        $this->assertStringStartsWith('Tresses, samedi 10 octobre', $recap['answer']);
        $this->assertSame('Très bien, je note.', $blocked['answer']);
        Http::assertSentCount(3);
    }

    public function test_appointment_reference_is_known_and_annotated_in_history(): void
    {
        $appointment = $this->makeAppointment('requested');
        DB::table('messages')->insert([
            ['id' => (string) Str::uuid(), 'conversation_id' => $this->conversationId, 'direction' => 'inbound', 'content' => 'Oui', 'metadata' => null, 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)],
            ['id' => (string) Str::uuid(), 'conversation_id' => $this->conversationId, 'direction' => 'outbound', 'content' => "Votre demande est enregistrée, référence {$appointment->reference()}.", 'metadata' => null, 'created_at' => now()->subMinutes(4), 'updated_at' => now()->subMinutes(4)],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse("Votre demande {$appointment->reference()} est bien enregistrée."))]);

        $result = $this->service()->answer($this->business, 'Vous avez bien ma demande ?', $this->conversationId);

        $this->assertSame("Votre demande {$appointment->reference()} est bien enregistrée.", $result['answer']);
        $this->assertStringEndsWith(
            "[Note système : demande de rendez-vous {$appointment->reference()} réellement enregistrée par l'outil create_appointment (créneau à confirmer par l'entreprise).]",
            Http::recorded()[0][0]->data()['messages'][1]['content'],
        );
    }

    public function test_index_filters_by_status_dates_and_search(): void
    {
        $saturday = $this->makeAppointment('requested', ['requested_date' => '2026-10-10', 'service' => 'Tresses']);
        $this->travel(1)->minutes();
        $monday = $this->makeAppointment('confirmed', ['requested_date' => '2026-10-12', 'service' => 'Coupe femme', 'location' => 'Ouaga 2000']);
        $this->travel(1)->minutes();
        $later = $this->makeAppointment('requested', [
            'requested_date' => '2026-10-20',
            'service' => 'Coupe femme',
            'customer_name' => 'Issa Kaboré',
            'customer_phone' => '22676543210',
        ]);

        Sanctum::actingAs($this->owner);

        $this->assertIndex([], [$later, $monday, $saturday]);
        $this->assertIndex(['status' => 'requested'], [$later, $saturday]);
        $this->assertIndex(['date_from' => '2026-10-11'], [$later, $monday]);
        $this->assertIndex(['date_to' => '2026-10-12'], [$monday, $saturday]);
        $this->assertIndex(['date_from' => '2026-10-11', 'date_to' => '2026-10-12'], [$monday]);
        $this->assertIndex(['q' => 'COUPE'], [$later, $monday]);
        $this->assertIndex(['q' => 'kaboré'], [$later]);
        $this->assertIndex(['q' => '6543'], [$later]);
        $this->assertIndex(['q' => 'ouaga'], [$monday]);
        $this->assertIndex(['q' => strtolower($saturday->reference())], [$saturday]);
        $this->assertIndex(['q' => 'coupe', 'status' => 'requested'], [$later]);
        $this->assertIndex(['q' => '%'], []);

        // Tri par date souhaitée croissante, puis par création (même date : $saturday, créé avant $tie).
        $tie = $this->makeAppointment('requested', ['requested_date' => '2026-10-10', 'service' => 'Coupe enfant']);
        $this->assertIndex(['sort' => 'requested_date'], [$saturday, $tie, $monday, $later]);
        $this->assertIndex(['sort' => 'requested_date', 'status' => 'requested', 'date_from' => '2026-10-10'], [$saturday, $tie, $later]);

        $base = "/api/v1/businesses/{$this->business->id}/appointments";
        $this->getJson("{$base}?sort=created_at")->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson("{$base}?status=booked")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson("{$base}?date_from=10/10/2026")->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson("{$base}?date_from=2026-10-12&date_to=2026-10-10")->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_show_returns_the_appointment(): void
    {
        $appointment = $this->makeAppointment('requested');
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('appointment.id', $appointment->id)
            ->assertJsonPath('appointment.requested_date', '2026-10-10')
            ->assertJsonPath('appointment.service', 'Tresses');
    }

    public function test_confirmation_is_sent_on_whatsapp_within_24_hours(): void
    {
        $appointment = $this->makeAppointment('requested');
        $this->addInboundMessage(now()->subHours(3));
        $this->expectWhatsAppMessage(
            "Bonne nouvelle 😊 Votre rendez-vous « Tresses » du samedi 10 octobre à 15h est confirmé. À bientôt chez Belle Coiffure !",
            ['messages' => [['id' => 'wamid.1']]],
        );

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'confirmed')
            ->assertJsonPath('customer_notified', true)
            ->assertJsonPath('notification_skipped_reason', null)
            ->assertJsonPath('message', 'Le statut du rendez-vous a été mis à jour et le client a été prévenu sur WhatsApp.');

        $message = DB::table('messages')->where('direction', 'outbound')->sole();
        $this->assertSame('human', $message->sender_type);
        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.1', $message->whatsapp_message_id);
        $this->assertSame(['appointment_id' => $appointment->id, 'appointment_status' => 'confirmed'], json_decode($message->metadata, true));
    }

    public function test_decline_is_sent_on_whatsapp_within_24_hours(): void
    {
        $appointment = $this->makeAppointment('requested');
        $this->addInboundMessage(now()->subMinutes(10));
        $this->expectWhatsAppMessage(
            "Nous sommes désolés, nous ne pouvons pas vous recevoir pour « Tresses » le samedi 10 octobre à 15h. N'hésitez pas à nous proposer un autre créneau.",
            ['messages' => [['id' => 'wamid.2']]],
        );

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'declined'])
            ->assertOk()
            ->assertJsonPath('customer_notified', true);
    }

    public function test_nothing_is_sent_outside_the_24_hour_window(): void
    {
        $appointment = $this->makeAppointment('requested');
        $this->addInboundMessage(now()->subHours(25));
        $this->expectNoWhatsAppMessage();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'confirmed')
            ->assertJsonPath('customer_notified', false)
            ->assertJsonPath('notification_skipped_reason', 'outside_24h_window');

        $this->assertSame(0, DB::table('messages')->where('direction', 'outbound')->count());
    }

    public function test_failed_whatsapp_send_is_reported(): void
    {
        $appointment = $this->makeAppointment('requested');
        $this->addInboundMessage(now()->subHour());
        $this->expectWhatsAppMessage(null, []);

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('customer_notified', false)
            ->assertJsonPath('notification_skipped_reason', 'send_failed');

        $this->assertSame('failed', DB::table('messages')->where('direction', 'outbound')->value('status'));
    }

    public function test_other_statuses_and_unchanged_status_send_nothing(): void
    {
        $appointment = $this->makeAppointment('confirmed');
        $this->addInboundMessage(now()->subHour());
        $this->expectNoWhatsAppMessage();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('customer_notified', false)
            ->assertJsonPath('notification_skipped_reason', 'not_applicable');
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'completed')
            ->assertJsonPath('notification_skipped_reason', 'not_applicable');
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'booked'])->assertUnprocessable();
    }

    public function test_endpoints_are_forbidden_without_the_module_or_for_other_users(): void
    {
        $appointment = $this->makeAppointment('requested');
        $this->expectNoWhatsAppMessage();

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));
        $this->getJson("/api/v1/businesses/{$this->business->id}/appointments")->assertForbidden();
        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertForbidden();
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertForbidden();

        $this->business->update(['modules' => ['orders']]);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/businesses/{$this->business->id}/appointments")
            ->assertForbidden()
            ->assertJsonPath('message', "Le module Rendez-vous n'est pas activé pour cette entreprise.");
        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertForbidden();
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertForbidden();

        $this->assertSame('requested', $appointment->fresh()->status);
    }

    public function test_owner_can_enable_the_appointments_module(): void
    {
        $this->business->update(['modules' => ['orders']]);
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['modules' => ['appointments', 'orders']])
            ->assertOk()
            ->assertJsonPath('business.modules', ['orders', 'appointments']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertRejected(array $overrides, string $message): void
    {
        try {
            app(AppointmentService::class)->createFromAi($this->business, $this->conversation(), $overrides + $this->validInput());
            $this->fail('La demande aurait dû être refusée : '.$message);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<int, Appointment>  $expected
     */
    private function assertIndex(array $query, array $expected): void
    {
        $ids = $this->getJson("/api/v1/businesses/{$this->business->id}/appointments?".http_build_query($query))
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame(array_map(fn (Appointment $appointment) => $appointment->id, $expected), $ids, 'Filtres : '.json_encode($query));
    }

    private function expectWhatsAppMessage(?string $text, array $response): void
    {
        $whatsApp = Mockery::mock(WhatsAppService::class);
        $whatsApp->shouldReceive('sendMessage')
            ->once()
            ->with('22670000001', $text ?? Mockery::type('string'))
            ->andReturn($response);

        $this->mock(WhatsAppServiceFactory::class, fn ($mock) => $mock->shouldReceive('forBusiness')->andReturn($whatsApp));
    }

    private function expectNoWhatsAppMessage(): void
    {
        $this->mock(WhatsAppServiceFactory::class, fn ($mock) => $mock->shouldNotReceive('forBusiness'));
    }

    private function addInboundMessage(\DateTimeInterface $at): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->conversationId,
            'direction' => 'inbound',
            'content' => 'Merci, j\'attends votre confirmation.',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAppointment(string $status, array $overrides = []): Appointment
    {
        return Appointment::create($overrides + [
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
            'service' => 'Tresses',
            'requested_date' => '2026-10-10',
            'requested_time' => '15h',
            'price' => 15000,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return [
            'service' => 'Tresses',
            'requested_date' => '2026-10-10',
            'requested_time' => '15h',
            'customer_name' => 'Awa Ouédraogo',
            'price' => 15000,
        ];
    }

    private function conversation(): Conversation
    {
        return Conversation::findOrFail($this->conversationId);
    }

    private function service(): AIResponseService
    {
        return new FixedContextAIResponseService(self::CONTEXT);
    }

    private function seedRecapConversation(): void
    {
        $messages = [
            ['inbound', 'Je veux des tresses samedi à 15h. Je suis Awa Ouédraogo.'],
            ['outbound', "Récapitulatif :\nTresses, samedi 10 octobre à 15h : 15 000 FCFA\nJ'enregistre votre demande ?"],
            ['inbound', 'oui'],
        ];

        foreach ($messages as $index => [$direction, $content]) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversationId,
                'direction' => $direction,
                'sender_type' => $direction === 'inbound' ? 'customer' : 'ai',
                'content' => $content,
                'created_at' => now()->subMinutes(10 - $index),
                'updated_at' => now()->subMinutes(10 - $index),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toolUseResponse(array $input): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => "J'enregistre votre demande."],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_appointment', 'input' => $input],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }
}
