<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessWebsiteTest extends TestCase
{
    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // Vraies migrations des tables concernées (les autres dépendent de pgvector).
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_04_042020_create_businesses_table.php',
            '2026_09_11_224217_create_business_templates_table.php',
            '2026_09_12_015531_rename_whatsapp_number_and_add_whatsapp_fields_to_businesses_table.php',
            '2026_10_08_100000_add_modules_to_businesses.php',
            '2026_09_06_003335_create_subscriptions_table.php',
            '2026_10_09_100000_create_plans_table.php',
            '2026_10_09_120000_create_plan_prices_table.php',
            '2026_10_09_160000_add_website_to_businesses.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }

        (new PlanSeeder())->run();
        (require base_path('database/migrations/2026_10_09_110000_rework_subscriptions_and_trials.php'))->up();

        // Tables comptées par GET /businesses/{business}.
        foreach (['documents', 'conversations', 'escalations'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->uuid('id')->primary();
                $blueprint->uuid('business_id');
                $blueprint->string('status')->nullable();
            });
        }

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => Str::random(8).'@example.test', 'password' => 'x', 'country' => 'BF']);
        $this->business = Business::create(['user_id' => $this->owner->id, 'name' => 'Joyce', 'type' => 'boutique', 'modules' => []]);
    }

    public function test_owner_sets_and_clears_the_website(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}")->assertOk()->assertJsonPath('business.website', null);

        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['website' => 'https://joyce-boutique.bf'])
            ->assertOk()
            ->assertJsonPath('business.website', 'https://joyce-boutique.bf');
        $this->getJson("/api/v1/businesses/{$this->business->id}")->assertJsonPath('business.website', 'https://joyce-boutique.bf');

        // Une mise à jour sans le champ ne l'efface pas.
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['city' => 'Ouagadougou'])->assertOk();
        $this->assertSame('https://joyce-boutique.bf', $this->business->fresh()->website);

        // Vide ou null : le site est retiré.
        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['website' => ''])
            ->assertOk()
            ->assertJsonPath('business.website', null);
    }

    public function test_website_must_be_a_valid_http_url(): void
    {
        Sanctum::actingAs($this->owner);

        foreach (['joyce-boutique.bf', 'pas une url', 'ftp://joyce-boutique.bf', 'javascript:alert(1)', 'https://'.str_repeat('a', 250).'.bf'] as $website) {
            $this->patchJson("/api/v1/businesses/{$this->business->id}", ['website' => $website])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('website');
        }

        $this->assertNull($this->business->fresh()->website);
    }

    public function test_website_can_be_given_at_creation(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/businesses', ['name' => 'Salon Fatou', 'type' => 'boutique', 'website' => 'http://salon-fatou.com'])
            ->assertCreated()
            ->assertJsonPath('business.website', 'http://salon-fatou.com');
    }
}
