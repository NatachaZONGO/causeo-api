<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Currency;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use CreatesConversationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
    }

    public function test_currency_follows_the_country(): void
    {
        foreach (['BF', 'CI', 'SN', 'ML', 'NE', 'TG', 'BJ', 'GW', 'bf', null, ''] as $country) {
            $this->assertSame('XOF', Currency::forCountry($country), (string) $country);
        }
        foreach (['FR', 'BE', 'DE', 'BG', 'HR'] as $country) {
            $this->assertSame('EUR', Currency::forCountry($country), $country);
        }
        foreach (['US', 'GB', 'CH', 'NG', 'GH', 'CM', 'MA'] as $country) {
            $this->assertSame('USD', Currency::forCountry($country), $country);
        }

        $this->assertTrue(Currency::isPayable('XOF'));
        $this->assertFalse(Currency::isPayable('EUR'));
        $this->assertFalse(Currency::isPayable('USD'));
    }

    public function test_seeder_fills_prices_per_currency(): void
    {
        $expected = [
            'starter' => ['XOF' => 9900, 'EUR' => 15, 'USD' => 15],
            'pro' => ['XOF' => 19900, 'EUR' => 29, 'USD' => 29],
            'business' => ['XOF' => 44900, 'EUR' => 69, 'USD' => 69],
        ];

        foreach ($expected as $slug => $amounts) {
            $plan = Plan::bySlug($slug);
            foreach ($amounts as $currency => $amount) {
                $this->assertSame(['currency' => $currency, 'amount' => $amount, 'period_days' => 30, 'daily_amount' => round($amount / 30, 2)], $plan->priceIn($currency));
            }
            // Le prix XOF reste celui affiché en FCFA.
            $this->assertSame($plan->price_fcfa, $amounts['XOF']);
        }

        $this->assertSame(9, PlanPrice::count());
        $this->assertSame(['currency' => 'EUR', 'amount' => 0, 'period_days' => null, 'daily_amount' => null], Plan::bySlug('free')->priceIn('EUR'));

        // Relancer le seeder ne duplique rien.
        (new PlanSeeder())->run();
        $this->assertSame(9, PlanPrice::count());
    }

    public function test_plans_endpoint_returns_prices_in_the_requested_currency(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('currency', 'XOF')
            ->assertJsonPath('payable', true)
            ->assertJsonPath('plans.2.price', ['currency' => 'XOF', 'amount' => 19900, 'period_days' => 30, 'daily_amount' => 663.33]);

        $eur = $this->getJson('/api/v1/plans?currency=EUR')
            ->assertOk()
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('payable', false)
            ->assertJsonPath('plans.0.price.amount', 0)
            ->assertJsonPath('plans.3.price.amount', 69);
        $this->assertSame([0, 15, 29, 69], $eur->json('plans.*.price.amount'));
        $this->assertEquals(0.97, $eur->json('plans.2.price.daily_amount'));

        $this->assertSame([0, 15, 29, 69], $this->getJson('/api/v1/plans?currency=USD')->json('plans.*.price.amount'));

        $this->getJson('/api/v1/plans?currency=GBP')->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_billing_shows_the_business_currency_and_payment_availability(): void
    {
        $owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
        Sanctum::actingAs($owner);

        $ouaga = $this->makeBusiness($owner, 'BF');
        $this->getJson("/api/v1/businesses/{$ouaga->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.currency', 'XOF')
            ->assertJsonPath('billing.plan.slug', 'pro')
            ->assertJsonPath('billing.plan.price', ['currency' => 'XOF', 'amount' => 19900, 'period_days' => 30, 'daily_amount' => 663.33])
            ->assertJsonPath('billing.payment', ['available' => true, 'currency' => 'XOF', 'methods' => ['orange_money', 'moov_money'], 'message' => null]);

        $paris = $this->makeBusiness($owner, 'FR');
        $this->getJson("/api/v1/businesses/{$paris->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.currency', 'EUR')
            ->assertJsonPath('billing.plan.price.amount', 29)
            ->assertJsonPath('billing.payment', ['available' => false, 'currency' => 'XOF', 'methods' => [], 'message' => 'Bientôt disponible']);

        $accra = $this->makeBusiness($owner, 'GH');
        $this->getJson("/api/v1/businesses/{$accra->id}/billing")
            ->assertJsonPath('billing.currency', 'USD')
            ->assertJsonPath('billing.plan.price.amount', 29)
            ->assertJsonPath('billing.payment.available', false);
    }

    private function makeBusiness(User $owner, string $country): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => "Boutique {$country}", 'country' => $country, 'modules' => '[]']);
        $business = Business::findOrFail($id);

        // Essai Pro, sans dépendre de l'essai déjà utilisé par le même gérant.
        app(BillingService::class)->assignPlan($business, Plan::bySlug('pro'));

        return $business->fresh();
    }
}
