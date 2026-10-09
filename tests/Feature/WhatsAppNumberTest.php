<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppNotConnectedException;
use App\Services\WhatsApp\WhatsAppService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionProperty;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class WhatsAppNumberTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Un numéro global est configuré : il ne doit jamais servir pour un business.
        config([
            'services.whatsapp.phone_number_id' => 'GLOBAL-PNID',
            'services.whatsapp.token' => 'system-token',
        ]);

        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);

        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $businessId,
            'user_id' => $this->owner->id,
            'name' => 'Belle Coiffure',
            'modules' => '["appointments"]',
        ]);
        $this->assignPlan($businessId);
        $this->business = Business::findOrFail($businessId);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $this->conversationId,
            'business_id' => $businessId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
        ]);
    }

    public function test_business_without_number_never_falls_back_to_the_global_number(): void
    {
        $this->expectException(WhatsAppNotConnectedException::class);
        $this->expectExceptionMessage("WhatsApp n'est pas connecté pour cette entreprise.");

        WhatsAppService::forBusiness($this->business);
    }

    public function test_business_with_number_sends_from_its_own_number(): void
    {
        $this->business->forceFill(['whatsapp_phone_number_id' => 'BUSINESS-PNID', 'whatsapp_token' => 'business-token']);
        $service = WhatsAppService::forBusiness($this->business);

        $this->assertSame('BUSINESS-PNID', $this->property($service, 'phoneNumberId'));
        $this->assertSame('Bearer business-token', $this->authorization($service));

        // Sans token propre, on garde le token de l'utilisateur système, pas le numéro global.
        $this->business->forceFill(['whatsapp_token' => null]);
        $service = WhatsAppService::forBusiness($this->business);

        $this->assertSame('BUSINESS-PNID', $this->property($service, 'phoneNumberId'));
        $this->assertSame('Bearer system-token', $this->authorization($service));
    }

    public function test_an_instance_without_number_refuses_to_send(): void
    {
        $service = new WhatsAppService();

        foreach ([
            fn () => $service->sendMessage('22670000001', 'Bonjour'),
            fn () => $service->sendImage('22670000001', 'https://example.test/menu.jpg'),
            fn () => $service->sendDocument('22670000001', 'https://example.test/menu.pdf', 'menu.pdf'),
            fn () => $service->markAsRead('wamid.1'),
        ] as $send) {
            try {
                $send();
                $this->fail('Un envoi sans numéro aurait dû échouer.');
            } catch (WhatsAppNotConnectedException $e) {
                $this->assertSame("WhatsApp n'est pas connecté pour cette entreprise.", $e->getMessage());
            }
        }

        // Analyser un webhook ne demande pas de numéro.
        $this->assertNull($service->parseIncomingMessage([]));
    }

    public function test_manual_reply_is_refused_when_whatsapp_is_not_connected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/conversations/{$this->conversationId}/reply", ['response' => 'Bonjour Awa !'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => "WhatsApp n'est pas connecté pour cette entreprise."]);

        $this->assertSame(0, DB::table('messages')->count());
    }

    public function test_appointment_status_change_reports_a_missing_connection(): void
    {
        $appointment = Appointment::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => '22670000001',
            'service' => 'Tresses',
            'requested_date' => now()->addDays(2)->format('Y-m-d'),
            'requested_time' => '15h',
            'status' => 'requested',
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->conversationId,
            'direction' => 'inbound',
            'content' => 'Merci !',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'confirmed')
            ->assertJsonPath('customer_notified', false)
            ->assertJsonPath('notification_skipped_reason', 'whatsapp_not_connected')
            ->assertJsonPath('message', "Le statut du rendez-vous a été mis à jour. WhatsApp n'est pas connecté pour cette entreprise : le client n'a pas été prévenu.");

        $this->assertSame(0, DB::table('messages')->where('direction', 'outbound')->count());
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    private function authorization(WhatsAppService $service): string
    {
        /** @var Client $client */
        $client = $this->property($service, 'client');

        return $client->getConfig('headers')['Authorization'];
    }
}
