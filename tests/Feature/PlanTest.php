<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Tests\TestCase;

class PlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('database/migrations/2026_10_09_100000_create_plans_table.php'))->up();
    }

    public function test_seeder_creates_the_five_plans_with_the_decided_limits(): void
    {
        $this->seed(PlanSeeder::class);

        $plans = Plan::query()->orderBy('sort_order')->get()->keyBy('slug');

        $this->assertSame(['free', 'starter', 'pro', 'business', 'internal'], $plans->keys()->all());

        $this->assertPlan($plans['free'], 0, null, 10, 'day', 1, [], false, true);
        $this->assertPlan($plans['starter'], 9900, 30, 750, 'period', null, [], true, true);
        $this->assertPlan($plans['pro'], 19900, 30, 2000, 'period', null, ['orders', 'appointments'], true, true);
        $this->assertPlan($plans['business'], 44900, 30, 6000, 'period', null, ['orders', 'appointments'], true, true);
        $this->assertPlan($plans['internal'], 0, null, null, null, null, ['orders', 'appointments'], true, false);

        $this->assertTrue($plans['pro']->allowsModule('orders'));
        $this->assertFalse($plans['free']->allowsModule('orders'));
        $this->assertTrue($plans['starter']->isPaid());
        $this->assertFalse($plans['internal']->isPaid());
    }

    public function test_badge_and_daily_price_highlight(): void
    {
        $this->seed(PlanSeeder::class);
        $plans = Plan::all()->keyBy('slug');

        $this->assertSame(
            ['free' => null, 'starter' => null, 'pro' => 'Le plus choisi', 'business' => null, 'internal' => null],
            $this->bySlug($plans, fn (Plan $plan) => $plan->badge),
        );
        $this->assertSame(
            ['free' => false, 'starter' => true, 'pro' => true, 'business' => true, 'internal' => false],
            $this->bySlug($plans, fn (Plan $plan) => $plan->highlight_daily_price),
        );
        $this->assertSame(
            ['free' => null, 'starter' => 330.0, 'pro' => 663.33, 'business' => 1496.67, 'internal' => null],
            $this->bySlug($plans, fn (Plan $plan) => $plan->dailyPrice()),
        );
    }

    public function test_features_are_worded_as_value_with_two_minutes_saved_per_reply(): void
    {
        $this->seed(PlanSeeder::class);
        $plans = Plan::all()->keyBy('slug');

        $this->assertSame([
            "Jusqu'à 66 h économisées par mois (2 000 réponses automatiques)",
            'Votre vendeuse disponible 24 h/24',
            'Commandes et rendez-vous pris directement sur WhatsApp',
            "L'assistant apprend de vos réponses",
            'Documents illimités',
        ], $plans['pro']->features);

        $this->assertSame("Jusqu'à 10 h économisées par mois (300 réponses automatiques)", $plans['free']->features[0]);
        $this->assertSame("Jusqu'à 25 h économisées par mois (750 réponses automatiques)", $plans['starter']->features[0]);
        $this->assertSame("Jusqu'à 200 h économisées par mois (6 000 réponses automatiques)", $plans['business']->features[0]);
        $this->assertNotContains('Commandes et rendez-vous pris directement sur WhatsApp', $plans['starter']->features);
    }

    public function test_seeder_is_idempotent_and_restores_edited_values(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::where('slug', 'pro')->update(['price_fcfa' => 1, 'badge' => null, 'is_active' => false]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(5, Plan::count());
        $pro = Plan::bySlug('pro');
        $this->assertSame(19900, $pro->price_fcfa);
        $this->assertSame('Le plus choisi', $pro->badge);
        $this->assertTrue($pro->is_active);
    }

    public function test_public_endpoint_lists_public_plans_in_order_without_authentication(): void
    {
        $this->seed(PlanSeeder::class);

        $response = $this->getJson('/api/v1/plans')->assertOk();

        $this->assertSame(['free', 'starter', 'pro', 'business'], $response->json('plans.*.slug'));
        $response->assertJsonPath('plans.0', [
            'slug' => 'free',
            'name' => 'Gratuit',
            'description' => 'Pour découvrir Causeo et répondre aux premières questions de vos clients.',
            'price_fcfa' => 0,
            'period_days' => null,
            'daily_price_fcfa' => null,
            'highlight_daily_price' => false,
            'badge' => null,
            'reply_limit' => 10,
            'reply_limit_period' => 'day',
            'document_limit' => 1,
            'modules' => [],
            'learning' => false,
            'features' => [
                "Jusqu'à 10 h économisées par mois (300 réponses automatiques)",
                'Votre vendeuse disponible 24 h/24, pour les 10 premiers clients du jour',
                "1 document pour informer l'assistant",
            ],
        ]);
        $response->assertJsonPath('plans.2.price_fcfa', 19900)
            ->assertJsonPath('plans.2.daily_price_fcfa', 663.33)
            ->assertJsonPath('plans.2.highlight_daily_price', true)
            ->assertJsonPath('plans.2.badge', 'Le plus choisi')
            ->assertJsonPath('plans.2.modules', ['orders', 'appointments']);
        $this->assertEquals(330, $response->json('plans.1.daily_price_fcfa'));
    }

    public function test_inactive_plans_are_hidden(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::where('slug', 'business')->update(['is_active' => false]);

        $this->assertSame(['free', 'starter', 'pro'], $this->getJson('/api/v1/plans')->json('plans.*.slug'));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Plan>  $plans
     * @return array<string, mixed>
     */
    private function bySlug($plans, callable $value): array
    {
        return collect(['free', 'starter', 'pro', 'business', 'internal'])
            ->mapWithKeys(fn (string $slug) => [$slug => $value($plans[$slug])])
            ->all();
    }

    /**
     * @param  list<string>  $modules
     */
    private function assertPlan(Plan $plan, int $price, ?int $periodDays, ?int $replyLimit, ?string $limitPeriod, ?int $documentLimit, array $modules, bool $learning, bool $public): void
    {
        $this->assertSame($price, $plan->price_fcfa, $plan->slug);
        $this->assertSame($periodDays, $plan->period_days, $plan->slug);
        $this->assertSame($replyLimit, $plan->reply_limit, $plan->slug);
        $this->assertSame($limitPeriod, $plan->reply_limit_period, $plan->slug);
        $this->assertSame($documentLimit, $plan->document_limit, $plan->slug);
        $this->assertSame($modules, $plan->modules, $plan->slug);
        $this->assertSame($learning, $plan->learning, $plan->slug);
        $this->assertSame($public, $plan->is_public, $plan->slug);
        $this->assertTrue($plan->is_active, $plan->slug);
    }
}
