<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class WhatsAppConnectionTest extends TestCase
{
    use CreatesConversationSchema;

    private const SECRET = 'test-app-secret';

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => self::SECRET,
            'services.whatsapp.api_url' => 'https://graph.facebook.com/v21.0/',
            'services.whatsapp.token' => 'system-token',
        ]);

        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);

        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $businessId,
            'user_id' => $this->owner->id,
            'name' => 'Joyce',
            'modules' => '[]',
            'whatsapp_phone_number_id' => 'PNID-1',
            'whatsapp_waba_id' => 'WABA-1',
            'whatsapp_token' => Crypt::encryptString('business-token'), // colonne chiffrée (cast encrypted)
            'whatsapp_verified' => true,
            'whatsapp_connected_at' => now(),
            'whatsapp_display_name' => 'Joyce Boutique',
        ]);
        $this->business = Business::findOrFail($businessId);
    }

    // --- Déconnexion depuis Causeo -------------------------------------------------

    public function test_disconnect_unsubscribes_the_app_from_the_waba_then_clears_the_columns(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/whatsapp/disconnect")
            ->assertOk()
            ->assertJsonPath('meta_unsubscribed', true);

        $this->assertDisconnected();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v21.0/WABA-1/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer business-token'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'deregister'));
        $this->assertSame(0, Notification::count());
    }

    public function test_disconnect_still_clears_the_columns_when_meta_refuses(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);
        Log::spy();
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/whatsapp/disconnect")
            ->assertOk()
            ->assertJsonPath('meta_unsubscribed', false);

        $this->assertDisconnected();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'désabonnement du WABA refusé')
            && $context['status'] === 400
            && $context['error'] === 'Invalid OAuth access token');
    }

    public function test_disconnect_still_clears_the_columns_when_meta_is_unreachable(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);
        Log::spy();
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/whatsapp/disconnect")
            ->assertOk()
            ->assertJsonPath('meta_unsubscribed', false);

        $this->assertDisconnected();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'désabonnement du WABA impossible'));
    }

    public function test_disconnect_without_waba_skips_meta(): void
    {
        $this->business->update(['whatsapp_waba_id' => null]);
        Http::fake();
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/whatsapp/disconnect")
            ->assertOk()
            ->assertJsonPath('meta_unsubscribed', false);

        $this->assertDisconnected();
        Http::assertNothingSent();
    }

    public function test_other_users_cannot_disconnect(): void
    {
        Http::fake();
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/whatsapp/disconnect")->assertForbidden();

        Http::assertNothingSent();
        $this->assertTrue($this->business->fresh()->whatsapp_verified);
    }

    // --- Déconnexion depuis Meta ou le téléphone (account_update) ------------------

    public function test_partner_removed_disconnects_the_business_and_notifies_the_owner(): void
    {
        $this->postAccountUpdate([
            'event' => 'PARTNER_REMOVED',
            'waba_info' => ['waba_id' => 'WABA-1', 'owner_business_id' => '2329417887457253'],
            'disconnection_info' => ['reason' => 'PRIMARY_INACTIVITY', 'initiated_by' => 'SYSTEM'],
        ], entryId: 'PARTNER-BUSINESS-ID')->assertOk();

        $this->assertDisconnected();

        $notification = Notification::sole();
        $this->assertSame('whatsapp', $notification->type);
        $this->assertSame('WhatsApp déconnecté', $notification->title);
        $this->assertSame(
            "Le numéro Joyce Boutique n'est plus relié à Causeo (accès de Causeo retiré dans Meta, motif : PRIMARY_INACTIVITY, à l'initiative de : SYSTEM). "
            ."L'assistant ne répond plus aux clients : reconnectez WhatsApp depuis la page WhatsApp.",
            $notification->body,
        );
        $this->assertSame(['event' => 'PARTNER_REMOVED'], $notification->data);
    }

    public function test_other_disconnecting_events_disconnect_the_business(): void
    {
        $cases = [
            ['event' => 'PARTNER_APP_UNINSTALLED', 'waba_info' => ['waba_id' => 'WABA-1', 'partner_app_id' => '869361281603019']],
            ['event' => 'ACCOUNT_DELETED'],
            ['event' => 'ACCOUNT_OFFBOARDED'],
            ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE', 'waba_ban_date' => 'October 8, 2026']],
        ];

        foreach ($cases as $value) {
            $this->reconnect();

            // Sans waba_info, le WABA est entry.id.
            $this->postAccountUpdate($value, entryId: 'WABA-1')->assertOk();

            $this->assertDisconnected($value['event']);
            $this->assertSame(1, Notification::where('type', 'whatsapp')->where('data->event', $value['event'])->count(), $value['event']);
        }

        $this->assertSame(4, Notification::where('type', 'whatsapp')->count());
    }

    public function test_informational_events_change_nothing(): void
    {
        foreach ([
            ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'SCHEDULE_FOR_DISABLE', 'waba_ban_date' => 'October 9, 2026']],
            ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE', 'waba_ban_date' => 'October 9, 2026']],
            ['event' => 'ACCOUNT_RESTRICTION', 'restriction_info' => [['restriction_type' => 'RESTRICTED_BIZ_INITIATED_MESSAGING', 'expiration' => 1641330498]]],
            ['event' => 'ACCOUNT_RECONNECTED'],
            ['event' => 'PARTNER_ADDED', 'waba_info' => ['waba_id' => 'WABA-1', 'owner_business_id' => '1']],
        ] as $value) {
            $this->postAccountUpdate($value, entryId: 'WABA-1')->assertOk();
        }

        $this->assertStillConnected();
        $this->assertSame(0, Notification::count());
    }

    public function test_account_update_for_an_unknown_waba_is_ignored(): void
    {
        Log::spy();

        $this->postAccountUpdate(['event' => 'PARTNER_REMOVED', 'waba_info' => ['waba_id' => 'WABA-OTHER']], entryId: 'X')->assertOk();

        $this->assertStillConnected();
        $this->assertSame(0, Notification::count());
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => str_contains($message, 'WABA sans business connecté')
            && $context['waba_id'] === 'WABA-OTHER');
    }

    public function test_unsigned_or_forged_account_update_is_ignored(): void
    {
        $value = ['event' => 'PARTNER_REMOVED', 'waba_info' => ['waba_id' => 'WABA-1']];

        $this->postAccountUpdate($value, entryId: 'X', secret: null)->assertOk();
        $this->postAccountUpdate($value, entryId: 'X', secret: 'wrong-secret')->assertOk();

        config(['services.whatsapp.app_secret' => '']);
        $this->postAccountUpdate($value, entryId: 'X', secret: '')->assertOk();

        $this->assertStillConnected();
        $this->assertSame(0, Notification::count());
    }

    // --- Message pour un numéro sans business connecté ----------------------------

    public function test_message_for_an_unknown_number_is_ignored_with_a_log(): void
    {
        Http::fake();
        Log::spy();

        $this->postJson('/api/webhook/whatsapp', $this->incomingMessage('PNID-UNKNOWN'))->assertOk();

        $this->assertSame(0, DB::table('conversations')->count());
        $this->assertSame(0, DB::table('messages')->count());
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'numéro sans business connecté')
            && ($context['phone_number_id'] ?? null) === 'PNID-UNKNOWN');
        Log::shouldNotHaveReceived('error');
    }

    public function test_message_for_a_disconnected_business_is_ignored(): void
    {
        // Numéro encore renseigné mais connexion non vérifiée : on ne répond pas.
        $this->business->update(['whatsapp_verified' => false]);
        Http::fake();
        Log::spy();

        $this->postJson('/api/webhook/whatsapp', $this->incomingMessage('PNID-1'))->assertOk();

        $this->assertSame(0, DB::table('conversations')->count());
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('error');
    }

    public function test_echoes_for_an_unknown_number_are_ignored(): void
    {
        Log::spy();

        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-X',
                'changes' => [[
                    'field' => 'smb_message_echoes',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-UNKNOWN'],
                        'message_echoes' => [['type' => 'text', 'to' => '22670000001', 'text' => ['body' => 'Bonjour']]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(0, DB::table('messages')->count());
        Log::shouldNotHaveReceived('error');
    }

    // --- Outils -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $value
     */
    private function postAccountUpdate(array $value, string $entryId, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $entryId,
                'time' => 1748477359,
                'changes' => [['field' => 'account_update', 'value' => $value]],
            ]],
        ]);

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], $headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingMessage(string $phoneNumberId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-X',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '22600000000', 'phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => '22670000001']],
                        'messages' => [[
                            'from' => '22670000001',
                            'id' => 'wamid.'.Str::random(10),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Bonjour'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function reconnect(): void
    {
        // Mise à jour directe : l'instance en mémoire a encore les anciennes valeurs, Eloquent n'écrirait rien.
        DB::table('businesses')->where('id', $this->business->id)->update([
            'whatsapp_phone_number_id' => 'PNID-1',
            'whatsapp_waba_id' => 'WABA-1',
            'whatsapp_token' => Crypt::encryptString('business-token'), // colonne chiffrée (cast encrypted)
            'whatsapp_verified' => true,
            'whatsapp_connected_at' => now(),
            'whatsapp_display_name' => 'Joyce Boutique',
        ]);
    }

    private function assertDisconnected(string $context = ''): void
    {
        $business = $this->business->fresh();

        $this->assertNull($business->whatsapp_phone_number_id, $context);
        $this->assertNull($business->whatsapp_waba_id, $context);
        $this->assertNull($business->whatsapp_token, $context);
        $this->assertFalse($business->whatsapp_verified, $context);
        $this->assertNull($business->whatsapp_connected_at, $context);
        $this->assertNull($business->whatsapp_display_name, $context);
    }

    private function assertStillConnected(): void
    {
        $business = $this->business->fresh();

        $this->assertSame('WABA-1', $business->whatsapp_waba_id);
        $this->assertSame('PNID-1', $business->whatsapp_phone_number_id);
        $this->assertTrue($business->whatsapp_verified);
    }
}
