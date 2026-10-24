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
            '2026_10_12_100000_add_whatsapp_alerts_to_users.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }
        (new PlanSeeder())->run();
        (require base_path(self::REWORK_MIGRATION))->up();
        (require base_path('database/migrations/2026_10_13_100000_add_trial_terms_to_subscriptions.php'))->up();

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

    public function test_onboarding_offers_a_pro_trial_of_15_days_or_300_units(): void
    {
        $user = $this->makeUser();
        $business = $this->onboard($user);

        $state = app(BillingService::class)->state($business);
        $this->assertSame('trialing', $state->status);
        $this->assertSame('pro', $state->plan->slug);
        $this->assertSame('2026-10-24 10:00:00', $state->endsAt->toDateTimeString());
        $this->assertSame(15, $state->daysLeft());
        $this->assertSame('pro', $business->fresh()->getRawOriginal('plan'));
        $this->assertSame(1, TrialUsage::where('user_id', $user->id)->where('business_id', $business->id)->count());

        $subscription = Subscription::where('business_id', $business->id)->sole();
        $this->assertTrue($subscription->trial_started_at->equalTo('2026-10-09 10:00:00'));
        $this->assertSame(300, $subscription->trial_unit_limit);

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/businesses/{$business->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.status', 'trialing')
            ->assertJsonPath('billing.plan.slug', 'pro')
            ->assertJsonPath('billing.plan.unit_limit', 2000)
            ->assertJsonPath('billing.plan.reply_limit', 2000)
            ->assertJsonPath('billing.days_left', 15)
            ->assertJsonPath('billing.grace_ends_at', null)
            ->assertJsonPath('billing.trial', [
                'started_at' => '2026-10-09T10:00:00+00:00',
                'ends_at' => '2026-10-24T10:00:00+00:00',
                'days' => 15,
                'units' => 300,
            ])
            // L'essai se compte en unités : 300, pas la limite mensuelle du Pro.
            ->assertJsonPath('billing.usage.limit', 300)
            ->assertJsonPath('billing.usage.used', 0);
    }

    public function test_trial_length_and_units_come_from_the_configuration(): void
    {
        config(['billing.trial_days' => 7, 'billing.trial_units' => 50]);
        $business = $this->onboard($this->makeUser());

        $this->assertSame('2026-10-16 10:00:00', $business->billingState()->endsAt->toDateTimeString());
        $this->assertSame(50, Subscription::where('business_id', $business->id)->value('trial_unit_limit'));

        // Les conditions sont figées au début de l'essai.
        config(['billing.trial_days' => 30, 'billing.trial_units' => 1000]);
        $this->assertSame('2026-10-16 10:00:00', $business->fresh()->billingState()->endsAt->toDateTimeString());
        $this->assertSame(50, app(\App\Services\Billing\UsageService::class)->usage($business->fresh())->limit);
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

        $this->travelTo('2026-10-23 10:00:00');
        $this->assertSame(['trialing', 1], $this->statusAndDaysLeft($business));

        $this->travelTo('2026-10-24 09:59:59');
        $this->assertSame('trialing', $business->billingState()->status);

        $this->travelTo('2026-10-24 10:00:00');
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

    public function test_admin_lists_every_active_plan_including_internal(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $plans = $this->getJson('/api/v1/admin/plans')->assertOk()->json('plans');

        $this->assertSame(['free', 'starter', 'pro', 'business', 'internal'], array_column($plans, 'slug'));
        $this->assertSame(['Gratuit', 'Starter', 'Pro', 'Business', 'Interne'], array_column($plans, 'name'));
        $this->assertFalse($plans[4]['is_public']);

        // Une formule désactivée ne peut plus être attribuée : elle disparaît de la liste.
        Plan::where('slug', 'business')->update(['is_active' => false]);
        $this->assertNotContains('business', array_column($this->getJson('/api/v1/admin/plans')->json('plans'), 'slug'));

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/plans')->assertForbidden();
    }

    public function test_admin_business_list_shows_the_effective_plan_and_status(): void
    {
        $business = $this->onboard($this->makeUser());
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $this->getJson('/api/v1/admin/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.billing.status', 'trialing')
            ->assertJsonPath('data.0.billing.plan.slug', 'pro')
            ->assertJsonPath('data.0.billing.plan.name', 'Pro')
            ->assertJsonPath('data.0.billing.days_left', 15);

        // Essai terminé, avant le passage horaire de billing:sync : la colonne dit encore « pro ».
        $this->travel(16)->days();
        $this->assertSame('pro', $business->fresh()->getRawOriginal('plan'));
        $this->getJson('/api/v1/admin/businesses')
            ->assertJsonPath('data.0.plan', 'pro')
            ->assertJsonPath('data.0.billing.status', 'free')
            ->assertJsonPath('data.0.billing.plan.slug', 'free');

        $this->putJson("/api/v1/admin/businesses/{$business->id}", ['plan' => 'internal'])
            ->assertOk()
            ->assertJsonPath('business.billing.status', 'active')
            ->assertJsonPath('business.billing.plan.slug', 'internal')
            ->assertJsonPath('business.billing.plan.name', 'Interne')
            ->assertJsonPath('business.billing.ends_at', null);
    }

    public function test_admin_plan_filter_uses_the_effective_plan(): void
    {
        $owner = $this->makeUser();
        $trial = $this->onboard($owner);
        $starter = $this->onboard($this->makeUser());
        $internal = $this->onboard($this->makeUser());
        $free = $this->onboard($this->makeUser());
        $billing = app(BillingService::class);
        $billing->assignPlan($starter, Plan::bySlug('starter'));
        $billing->assignPlan($internal, Plan::bySlug('internal'));
        $billing->assignPlan($free, Plan::bySlug('free'));
        // Deuxième business du même gérant : pas de second essai, donc Gratuit.
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/businesses', ['name' => 'Sans essai', 'type' => 'boutique'])->assertCreated();

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $expectations = [
            // Aujourd'hui : essai Pro, Starter actif, Interne, deux Gratuit.
            '2026-10-09 10:00:00' => ['pro' => [$trial], 'starter' => [$starter], 'internal' => [$internal]],
            // Essai fini (la colonne dit encore « pro ») ; Starter échu le 8/11 mais en grâce jusqu'au 11/11.
            '2026-11-10 10:00:00' => ['pro' => [], 'starter' => [$starter], 'internal' => [$internal]],
            // Grâce dépassée : Starter passe en Gratuit.
            '2026-11-11 10:00:00' => ['pro' => [], 'starter' => [], 'internal' => [$internal]],
        ];

        foreach ($expectations as $date => $paid) {
            $this->travelTo($date);
            foreach (['free', 'starter', 'pro', 'business', 'internal'] as $slug) {
                $expected = Business::all()
                    ->filter(fn (Business $business) => $business->billingState()->plan->slug === $slug)
                    ->pluck('id')->sort()->values()->all();
                $filtered = collect($this->getJson("/api/v1/admin/businesses?plan={$slug}")->assertOk()->json('data'))
                    ->pluck('id')->sort()->values()->all();

                $this->assertSame($expected, $filtered, "Filtre « {$slug} » le {$date}");
                if (isset($paid[$slug])) {
                    $this->assertSame(collect($paid[$slug])->pluck('id')->sort()->values()->all(), $filtered, "Formule {$slug} le {$date}");
                }
            }
        }
    }

    public function test_admin_status_filter_reads_is_active(): void
    {
        $active = $this->onboard($this->makeUser());
        $inactive = $this->onboard($this->makeUser());
        $inactive->forceFill(['is_active' => false])->save();
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $this->getJson('/api/v1/admin/businesses?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/v1/admin/businesses?is_active=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/v1/admin/businesses')->assertJsonCount(2, 'data')->assertJsonPath('data.0.user.name', 'Gérant');
    }

    public function test_admin_list_shows_whether_owner_whatsapp_alerts_are_active(): void
    {
        $active = $this->onboard($this->makeUser());
        $active->user->forceFill([
            'notification_phone' => '+22670112233',
            'whatsapp_alerts_enabled' => true,
            'whatsapp_alerts_consented_at' => now(),
        ])->save();
        $withoutConsent = $this->onboard($this->makeUser());
        $withoutConsent->user->forceFill(['notification_phone' => '+22670998877', 'whatsapp_alerts_enabled' => true])->save();
        $inactive = $this->onboard($this->makeUser());
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'country' => 'BF', 'is_admin' => true]));

        $list = collect($this->getJson('/api/v1/admin/businesses')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($list[$active->id]['whatsapp_alerts_active']);
        $this->assertFalse($list[$withoutConsent->id]['whatsapp_alerts_active']);
        $this->assertFalse($list[$inactive->id]['whatsapp_alerts_active']);
        // Le numéro d'alerte du gérant reste privé.
        $this->assertArrayNotHasKey('notification_phone', $list[$active->id]['user']);

        $this->getJson('/api/v1/admin/businesses?whatsapp_alerts=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->assertEqualsCanonicalizing(
            [$withoutConsent->id, $inactive->id],
            collect($this->getJson('/api/v1/admin/businesses?whatsapp_alerts=0')->json('data'))->pluck('id')->all(),
        );
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
