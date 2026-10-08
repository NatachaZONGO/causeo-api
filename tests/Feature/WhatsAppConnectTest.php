<?php

namespace Tests\Feature;

use App\Models\Business;
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

class WhatsAppConnectTest extends TestCase
{
    use CreatesConversationSchema;

    private const GRAPH = 'https://graph.facebook.com/v21.0/';

    private const CODE = 'embedded-signup-code-123';

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.app_id' => 'APP-ID',
            'services.facebook.app_secret' => 'APP-SECRET',
            'services.whatsapp.token' => 'system-token',
            'services.whatsapp.api_url' => self::GRAPH,
        ]);

        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);
        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $businessId, 'user_id' => $this->owner->id, 'name' => 'Joyce', 'modules' => '[]']);
        $this->business = Business::findOrFail($businessId);

        Log::spy();
    }

    public function test_code_is_exchanged_for_a_business_token_used_for_every_graph_call(): void
    {
        $this->fakeGraph(oauth: Http::response(['access_token' => 'business-token-xyz', 'token_type' => 'bearer']));

        $this->connect(['code' => self::CODE])
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('display_name', 'Joyce Boutique');

        $business = $this->business->fresh();
        $this->assertSame('business-token-xyz', $business->whatsapp_token);
        $this->assertSame('PNID-1', $business->whatsapp_phone_number_id);
        $this->assertSame('WABA-1', $business->whatsapp_waba_id);
        $this->assertTrue($business->whatsapp_verified);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::GRAPH.'oauth/access_token?')
            && $request['client_id'] === 'APP-ID'
            && $request['client_secret'] === 'APP-SECRET'
            && $request['code'] === self::CODE);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::GRAPH.'PNID-1?')
            && $request->hasHeader('Authorization', 'Bearer business-token-xyz'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::GRAPH.'WABA-1/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer business-token-xyz'));
        Http::assertSentCount(3);
        $this->assertNeverRegistered();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_failed_exchange_falls_back_to_the_system_token_without_logging_secrets(): void
    {
        $this->fakeGraph(oauth: Http::response(['error' => [
            'message' => 'Error validating verification code. Please make sure your redirect_uri is identical.',
            'type' => 'OAuthException',
            'code' => 100,
        ]], 400));

        $this->connect(['code' => self::CODE])->assertOk()->assertJsonPath('connected', true);

        $this->assertSame('system-token', $this->business->fresh()->whatsapp_token);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::GRAPH.'PNID-1?')
            && $request->hasHeader('Authorization', 'Bearer system-token'));
        Http::assertSent(fn (Request $request) => $request->url() === self::GRAPH.'WABA-1/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer system-token'));
        $this->assertNeverRegistered();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $logged = $message.json_encode($context);

            return str_contains($message, 'échange du code Embedded Signup refusé')
                && $context === ['business_id' => $this->business->id, 'status' => 400, 'error_type' => 'OAuthException', 'error_code' => 100]
                && ! str_contains($logged, self::CODE)
                && ! str_contains($logged, 'APP-SECRET')
                && ! str_contains($logged, 'system-token');
        });
    }

    public function test_unreachable_exchange_also_falls_back_to_the_system_token(): void
    {
        $this->fakeGraph(oauth: Http::failedConnection());

        $this->connect(['code' => self::CODE])->assertOk();

        $this->assertSame('system-token', $this->business->fresh()->whatsapp_token);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'échange du code Embedded Signup impossible')
            && array_keys($context) === ['business_id', 'exception']);
    }

    public function test_without_code_the_system_token_is_kept_with_a_warning(): void
    {
        $this->fakeGraph();

        $this->connect()->assertOk();

        $this->assertSame('system-token', $this->business->fresh()->whatsapp_token);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth/access_token'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'sans code Embedded Signup')
            && $context === ['business_id' => $this->business->id]);
    }

    public function test_invalid_phone_number_leaves_the_business_unchanged(): void
    {
        Http::fake([
            self::GRAPH.'oauth/access_token*' => Http::response(['access_token' => 'business-token-xyz']),
            self::GRAPH.'PNID-1*' => Http::response(['error' => ['message' => 'Unsupported get request.']], 400),
            '*' => Http::response(['success' => true]),
        ]);

        $this->connect(['code' => self::CODE])->assertUnprocessable();

        $business = $this->business->fresh();
        $this->assertNull($business->whatsapp_token);
        $this->assertFalse($business->whatsapp_verified);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'subscribed_apps'));
    }

    public function test_token_is_encrypted_in_the_database_and_readable_through_the_model(): void
    {
        $this->fakeGraph(oauth: Http::response(['access_token' => 'business-token-xyz']));

        $this->connect(['code' => self::CODE])->assertOk();

        $raw = DB::table('businesses')->where('id', $this->business->id)->value('whatsapp_token');
        $this->assertNotSame('business-token-xyz', $raw);
        $this->assertStringNotContainsString('business-token-xyz', $raw);
        $this->assertSame('business-token-xyz', Crypt::decryptString($raw));
        $this->assertSame('business-token-xyz', $this->business->fresh()->whatsapp_token);

        // Jamais exposé par l'API.
        Sanctum::actingAs($this->owner);
        $this->assertArrayNotHasKey('whatsapp_token', $this->business->fresh()->toArray());
    }

    public function test_migration_encrypts_existing_plain_tokens_and_leaves_encrypted_ones(): void
    {
        $plainId = $this->insertBusinessWithRawToken('plain-legacy-token');
        $alreadyEncrypted = Crypt::encryptString('already-encrypted-token');
        $encryptedId = $this->insertBusinessWithRawToken($alreadyEncrypted);
        $emptyId = $this->insertBusinessWithRawToken(null);

        $migration = require base_path('database/migrations/2026_10_08_130000_encrypt_whatsapp_tokens.php');
        $migration->up();

        $this->assertNotSame('plain-legacy-token', $this->rawToken($plainId));
        $this->assertSame('plain-legacy-token', Business::find($plainId)->whatsapp_token);
        $this->assertSame($alreadyEncrypted, $this->rawToken($encryptedId));
        $this->assertSame('already-encrypted-token', Business::find($encryptedId)->whatsapp_token);
        $this->assertNull($this->rawToken($emptyId));

        // Relancer la migration ne chiffre pas deux fois.
        $migration->up();
        $this->assertSame('plain-legacy-token', Business::find($plainId)->whatsapp_token);

        $migration->down();
        $this->assertSame('plain-legacy-token', $this->rawToken($plainId));
        $this->assertSame('already-encrypted-token', $this->rawToken($encryptedId));
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function connect(array $extra = []): TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp/connect", [
            'waba_id' => 'WABA-1',
            'phone_number_id' => 'PNID-1',
        ] + $extra);
    }

    private function fakeGraph(mixed $oauth = null): void
    {
        Http::fake([
            self::GRAPH.'oauth/access_token*' => $oauth ?? Http::response(['access_token' => 'unused']),
            self::GRAPH.'PNID-1*' => Http::response(['id' => 'PNID-1', 'verified_name' => 'Joyce Boutique', 'display_phone_number' => '+226 70 00 00 00']),
            self::GRAPH.'WABA-1/subscribed_apps' => Http::response(['success' => true]),
            '*' => Http::response(['error' => ['message' => 'Appel inattendu']], 500),
        ]);
    }

    private function assertNeverRegistered(): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/register') || str_contains($request->url(), '/deregister'));
    }

    private function insertBusinessWithRawToken(?string $token): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Legacy', 'modules' => '[]', 'whatsapp_token' => $token]);

        return $id;
    }

    private function rawToken(string $id): ?string
    {
        return DB::table('businesses')->where('id', $id)->value('whatsapp_token');
    }
}
