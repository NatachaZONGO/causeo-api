<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialUsage;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    private const REWORK_MIGRATION = 'database/migrations/2026_10_09_110000_rework_subscriptions_and_trials.php';

    private string $templateId;

    private string $displayNumber = '';

    private bool $graphFaked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00:00');
        config(['services.whatsapp.token' => 'system-token', 'services.whatsapp.api_url' => 'https://graph.facebook.com/v21.0/']);

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_04_042020_create_businesses_table.php',
            '2026_09_11_200158_add_is_admin_to_users_table.php',
            '2026_09_11_224217_create_business_templates_table.php',
            '2026_09_12_015531_rename_whatsapp_number_and_add_whatsapp_fields_to_businesses_table.php',
            '2026_10_08_100000_add_modules_to_businesses.php',
            '2026_09_06_003335_create_subscriptions_table.php',
            '2026_10_09_100000_create_plans_table.php',
            '2026_10_09_120000_create_plan_prices_table.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }
        (new PlanSeeder())->run();
        (require base_path(self::REWORK_MIGRATION))->up();

        // Tables comptées par GET /businesses/{business}.
        foreach (['documents', 'conversations', 'escalations'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->uuid('id')->primary();
                $blueprint->uuid('business_id');
                $blueprint->string('status')->nullable();
            });
        }
        // Réponses du bot comptées pour l'usage (GET /businesses/{business}/billing).
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('direction');
            $table->string('sender_type')->nullable();
            $table->string('status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->templateId = (string) Str::uuid();
        DB::table('business_templates')->insert([
            'id' => $this->templateId,
            'slug' => 'boutique',
            'name' => 'Boutique',
            'icon' => '•',
            'description' => 'Boutique',
            'type' => 'boutique',
            'default_greeting' => 'Bienvenue chez {nom} !',
            'default_ai_instructions' => 'Tu es l\'assistant de {nom}.',
            'default_modules' => json_encode(['orders']),
        ]);
    }

    public function test_onboarding_offers_30_days_of_pro(): void
    {
        $user = $this->makeUser();
        $business = $this->onboard($user);

        $state = app(BillingService::class)->state($business);
        $this->assertSame('trialing', $state->status);
        $this->assertSame('pro', $state->plan->slug);
        $this->assertSame('2026-11-08 10:00:00', $state->endsAt->toDateTimeString());
        $this->assertSame(30, $state->daysLeft());
        $this->assertSame('pro', $business->fresh()->getRawOriginal('plan'));
        $this->assertSame(1, TrialUsage::where('user_id', $user->id)->where('business_id', $business->id)->count());

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/businesses/{$business->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.status', 'trialing')
            ->assertJsonPath('billing.plan.slug', 'pro')
            ->assertJsonPath('billing.plan.reply_limit', 2000)
            ->assertJsonPath('billing.days_left', 30)
            ->assertJsonPath('billing.grace_ends_at', null);
    }

    public function test_a_user_gets_only_one_trial(): void
    {
        $user = $this->makeUser();
        $this->onboard($user);

        Sanctum::actingAs($user);
        $second = $this->postJson('/api/v1/businesses', ['name' => 'Deuxième boutique', 'type' => 'boutique'])
            ->assertCreated()
            ->assertJsonPath('business.plan', 'free')
            ->json('business.id');

        $state = Business::findOrFail($second)->billingState();
        $this->assertSame('free', $state->status);
        $this->assertSame('free', $state->plan->slug);
        $this->assertNull(Subscription::where('business_id', $second)->first());
    }

    public function test_trial_switches_to_free_at_its_end_without_any_job(): void
    {
        $business = $this->onboard($this->makeUser());

        $this->travelTo('2026-11-07 10:00:00');
        $this->assertSame(['trialing', 1], $this->statusAndDaysLeft($business));

        $this->travelTo('2026-11-08 09:59:59');
        $this->assertSame('trialing', $business->billingState()->status);

        $this->travelTo('2026-11-08 10:00:00');
        $state = $business->billingState();
        $this->assertSame('free', $state->status);
        $this->assertSame('free', $state->plan->slug);
        $this->assertNull($state->daysLeft());
        // Rien n'a été persisté : seul le calcul a changé.
        $this->assertSame('trialing', $business->subscription()->first()->status);
    }

    public function test_paid_plan_has_3_days_of_grace_then_switches_to_free(): void
    {
        $business = $this->onboard($this->makeUser());
        app(BillingService::class)->assignPlan($business, Plan::bySlug('starter'));

        $this->assertSame(['active', 30], $this->statusAndDaysLeft($business));
        $this->assertSame('starter', $business->fresh()->getRawOriginal('plan'));

        $this->travelTo('2026-11-08 10:00:00');
        $state = $business->billingState();
        $this->assertSame('grace', $state->status);
        $this->assertSame('starter', $state->plan->slug);
        $this->assertSame(3, $state->daysLeft());
        $this->assertSame('2026-11-11 10:00:00', $state->graceEndsAt->toDateTimeString());

        $this->travelTo('2026-11-11 09:59:59');
        $this->assertSame('grace', $business->billingState()->status);

        $this->travelTo('2026-11-11 10:00:00');
        $this->assertSame('free', $business->billingState()->status);
    }

    public function test_internal_plan_never_expires(): void
    {
        $business = $this->onboard($this->makeUser());
        app(BillingService::class)->assignPlan($business, Plan::bySlug('internal'));

        $this->travelTo('2031-01-01 00:00:00');
        $state = $business->billingState();
        $this->assertSame('active', $state->status);
        $this->assertSame('internal', $state->plan->slug);
        $this->assertNull($state->endsAt);
        $this->assertNull($state->daysLeft());
    }

    public function test_a_whatsapp_number_gets_only_one_trial(): void
    {
        $first = $this->onboard($this->makeUser());
        $this->connectWhatsApp($first, '+226 70 00 00 01');
        $this->assertSame('trialing', $first->billingState()->status);
        $this->assertSame('22670000001', TrialUsage::where('business_id', $first->id)->value('whatsapp_number'));

        // Un autre utilisateur, même numéro (écrit autrement) : pas de second essai.
        $second = $this->onboard($this->makeUser());
        $this->assertSame('trialing', $second->billingState()->status);
        $this->connectWhatsApp($second, '22670000001');

        $state = $second->fresh()->billingState();
        $this->assertSame('free', $state->status);
        $this->assertSame('free', $second->fresh()->getRawOriginal('plan'));
        $this->assertSame('expired', $second->subscription()->first()->status);

        // Un nouveau numéro garde son essai.
        $third = $this->onboard($this->makeUser());
        $this->connectWhatsApp($third, '+226 76 00 00 09');
        $this->assertSame('trialing', $third->fresh()->billingState()->status);

        // Reconnecter le numéro de son propre essai ne l'annule pas.
        $this->connectWhatsApp($first, '+226 70 00 00 01');
        $this->assertSame('trialing', $first->fresh()->billingState()->status);
    }

    public function test_number_of_a_deleted_business_still_counts(): void
    {
        $first = $this->onboard($this->makeUser());
        $this->connectWhatsApp($first, '+226 70 00 00 01');
        $first->delete();

        $second = $this->onboard($this->makeUser());
        $this->connectWhatsApp($second, '+226 70 00 00 01');

        $this->assertSame('free', $second->fresh()->billingState()->status);
    }

    public function test_admin_assigns_plans_through_the_subscription(): void
    {
        $owner = $this->makeUser();
        $business = $this->onboard($owner);

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $this->putJson("/api/v1/admin/businesses/{$business->id}", ['plan' => 'business'])
            ->assertOk()
            ->assertJsonPath('business.plan', 'business');
        $this->assertSame(['active', 30], $this->statusAndDaysLeft($business));

        $this->putJson("/api/v1/admin/businesses/{$business->id}", ['plan' => 'enterprise'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        $this->putJson("/api/v1/admin/businesses/{$business->id}", ['plan' => 'free'])->assertOk()->assertJsonPath('business.plan', 'free');
        $this->assertSame('free', $business->billingState()->status);

        // Un gérant n'a pas accès à l'admin.
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/admin/businesses/{$business->id}", ['plan' => 'internal'])->assertForbidden();
    }

    public function test_billing_endpoint_is_private(): void
    {
        $business = $this->onboard($this->makeUser());

        Sanctum::actingAs($this->makeUser());
        $this->getJson("/api/v1/businesses/{$business->id}/billing")->assertForbidden();
    }

    public function test_migration_moves_existing_businesses_to_internal_and_refuses_existing_subscriptions(): void
    {
        // État d'avant le déploiement : ancienne table subscriptions, businesses en « free ».
        $migration = require base_path(self::REWORK_MIGRATION);
        $migration->down();

        $owner = $this->makeUser();
        $ids = [];
        foreach (['Joyce', 'Chez Fatou'] as $name) {
            $ids[] = $id = (string) Str::uuid();
            DB::table('businesses')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => $name, 'type' => 'boutique', 'plan' => 'free', 'modules' => '[]']);
        }

        // Une table subscriptions non vide n'est jamais supprimée.
        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $ids[0], 'plan' => 'pro', 'price' => 15000, 'status' => 'active',
        ]);
        try {
            $migration->up();
            $this->fail('La migration aurait dû refuser une table subscriptions non vide.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contient des lignes', $e->getMessage());
        }
        DB::table('subscriptions')->delete();

        $migration->up();

        foreach ($ids as $id) {
            $business = Business::findOrFail($id);
            $this->assertSame('internal', $business->getRawOriginal('plan'));
            $state = $business->billingState();
            $this->assertSame(['active', 'internal', null], [$state->status, $state->plan->slug, $state->endsAt]);
        }
    }

    private function onboard(User $user): Business
    {
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/onboarding', ['template_id' => $this->templateId, 'business_name' => 'Boutique '.Str::random(4)])
            ->assertCreated()
            ->json('business.id');

        return Business::findOrFail($id);
    }

    private function connectWhatsApp(Business $business, string $displayNumber): void
    {
        // Un seul faux Graph pour tout le test (les appels successifs à Http::fake s'empilent).
        $this->displayNumber = $displayNumber;
        if (! $this->graphFaked) {
            Http::fake(fn ($request) => match (true) {
                str_contains($request->url(), 'oauth/access_token') => Http::response(['error' => ['type' => 'OAuthException']], 400),
                str_contains($request->url(), 'subscribed_apps') => Http::response(['success' => true]),
                default => Http::response(['id' => 'PNID', 'verified_name' => 'Boutique', 'display_phone_number' => $this->displayNumber]),
            });
            $this->graphFaked = true;
        }

        Sanctum::actingAs($business->user);
        $this->postJson("/api/v1/businesses/{$business->id}/whatsapp/connect", [
            'waba_id' => 'WABA-'.Str::random(4),
            'phone_number_id' => 'PNID-'.Str::random(4),
        ])->assertOk();
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function statusAndDaysLeft(Business $business): array
    {
        $state = $business->fresh()->billingState();

        return [$state->status, $state->daysLeft()];
    }

    private function makeUser(): User
    {
        return User::forceCreate(['name' => 'Gérant', 'email' => Str::random(8).'@example.test', 'password' => 'x', 'country' => 'BF']);
    }
}
