<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessTemplate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    private const MODULES_MIGRATION = 'database/migrations/2026_10_08_100000_add_modules_to_businesses.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Vraies migrations des tables concernées (les autres dépendent de pgvector).
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_04_042020_create_businesses_table.php',
            '2026_09_11_224217_create_business_templates_table.php',
            '2026_09_12_015531_rename_whatsapp_number_and_add_whatsapp_fields_to_businesses_table.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }

        // Tables comptées par GET /businesses/{business}.
        foreach (['documents', 'conversations', 'escalations'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->uuid('id')->primary();
                $blueprint->uuid('business_id');
                $blueprint->string('status')->nullable();
            });
        }
    }

    public function test_migration_sets_template_defaults_and_backfills_businesses_by_type(): void
    {
        foreach (['restaurant' => 'restaurant', 'boutique' => 'boutique', 'photographe' => 'photographe', 'autre' => 'other'] as $slug => $type) {
            $this->insertTemplate($slug, $type);
        }

        $owner = $this->makeUser();
        $ids = [];
        foreach (['restaurant', 'boutique', 'photographe', 'other', 'pharmacy'] as $type) {
            $ids[$type] = (string) Str::uuid();
            DB::table('businesses')->insert(['id' => $ids[$type], 'user_id' => $owner->id, 'name' => $type, 'type' => $type]);
        }

        (require base_path(self::MODULES_MIGRATION))->up();

        $this->assertSame(['orders'], BusinessTemplate::where('slug', 'restaurant')->value('default_modules'));
        $this->assertSame(['orders'], BusinessTemplate::where('slug', 'boutique')->value('default_modules'));
        $this->assertSame([], BusinessTemplate::where('slug', 'photographe')->value('default_modules'));
        $this->assertSame([], BusinessTemplate::where('slug', 'autre')->value('default_modules'));

        $this->assertSame(['orders'], Business::find($ids['restaurant'])->modules);
        $this->assertSame(['orders'], Business::find($ids['boutique'])->modules);
        $this->assertSame([], Business::find($ids['photographe'])->modules);
        $this->assertSame([], Business::find($ids['other'])->modules);
        $this->assertSame([], Business::find($ids['pharmacy'])->modules); // type sans template
    }

    public function test_appointments_migration_adds_the_module_without_removing_others(): void
    {
        (require base_path(self::MODULES_MIGRATION))->up();
        $this->insertTemplate('restaurant', 'restaurant', ['orders']);
        $this->insertTemplate('photographe', 'photographe', []);
        $this->insertTemplate('boutique', 'boutique', ['orders']);
        $this->insertTemplate('formation', 'formation', []);

        $owner = $this->makeUser();
        $businesses = [
            'restaurant' => ['restaurant', ['orders']],
            'restaurant_off' => ['restaurant', []],
            'photographe' => ['photographe', []],
            'clinic' => ['clinic', []],
            'boutique' => ['boutique', ['orders']],
            'formation' => ['formation', []],
        ];
        $ids = [];
        foreach ($businesses as $key => [$type, $modules]) {
            $ids[$key] = Business::create(['user_id' => $owner->id, 'name' => $key, 'type' => $type, 'modules' => $modules])->id;
        }

        (require base_path('database/migrations/2026_10_07_100000_create_notifications_table.php'))->up();
        (require base_path('database/migrations/2026_10_08_110000_add_appointments_module.php'))->up();

        $this->assertSame(['orders', 'appointments'], BusinessTemplate::where('slug', 'restaurant')->value('default_modules'));
        $this->assertSame(['appointments'], BusinessTemplate::where('slug', 'photographe')->value('default_modules'));
        $this->assertSame(['orders'], BusinessTemplate::where('slug', 'boutique')->value('default_modules'));
        $this->assertSame([], BusinessTemplate::where('slug', 'formation')->value('default_modules'));

        $this->assertSame(['orders', 'appointments'], Business::find($ids['restaurant'])->modules);
        $this->assertSame(['appointments'], Business::find($ids['restaurant_off'])->modules);
        $this->assertSame(['appointments'], Business::find($ids['photographe'])->modules);
        $this->assertSame(['appointments'], Business::find($ids['clinic'])->modules);
        $this->assertSame(['orders'], Business::find($ids['boutique'])->modules);
        $this->assertSame([], Business::find($ids['formation'])->modules);
    }

    public function test_onboarding_applies_the_template_modules(): void
    {
        (require base_path(self::MODULES_MIGRATION))->up();
        $boutique = $this->insertTemplate('boutique', 'boutique', ['orders']);
        $salon = $this->insertTemplate('salon_beaute', 'salon_beaute', []);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/onboarding', ['template_id' => $boutique, 'business_name' => 'Joyce'])
            ->assertCreated()
            ->assertJsonPath('business.modules', ['orders']);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/onboarding', ['template_id' => $salon, 'business_name' => 'Belle Coiffure'])
            ->assertCreated()
            ->assertJsonPath('business.modules', []);
    }

    public function test_business_creation_uses_the_modules_of_the_template_of_the_same_type(): void
    {
        (require base_path(self::MODULES_MIGRATION))->up();
        $this->insertTemplate('restaurant', 'restaurant', ['orders']);
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/businesses', ['name' => 'Chez Fatou', 'type' => 'restaurant'])
            ->assertCreated()
            ->assertJsonPath('business.modules', ['orders']);

        $this->postJson('/api/v1/businesses', ['name' => 'Clinique', 'type' => 'clinic'])
            ->assertCreated()
            ->assertJsonPath('business.modules', []);
    }

    public function test_owner_can_read_and_update_modules_with_allowed_values_only(): void
    {
        (require base_path(self::MODULES_MIGRATION))->up();
        $owner = $this->makeUser();
        $business = Business::create(['user_id' => $owner->id, 'name' => 'Joyce', 'type' => 'boutique', 'modules' => []]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/businesses/{$business->id}")->assertOk()->assertJsonPath('business.modules', []);

        $this->patchJson("/api/v1/businesses/{$business->id}", ['modules' => ['orders', 'orders']])
            ->assertOk()
            ->assertJsonPath('business.modules', ['orders']);
        $this->assertTrue($business->fresh()->hasModule('orders'));

        $this->patchJson("/api/v1/businesses/{$business->id}", ['modules' => ['orders', 'stock']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('modules.1');
        $this->patchJson("/api/v1/businesses/{$business->id}", ['modules' => 'orders'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('modules');
        $this->assertSame(['orders'], $business->fresh()->modules);

        // Une mise à jour sans « modules » ne les touche pas.
        $this->patchJson("/api/v1/businesses/{$business->id}", ['city' => 'Ouagadougou'])->assertOk();
        $this->assertSame(['orders'], $business->fresh()->modules);

        $this->patchJson("/api/v1/businesses/{$business->id}", ['modules' => []])
            ->assertOk()
            ->assertJsonPath('business.modules', []);
        $this->assertFalse($business->fresh()->hasModule('orders'));
    }

    public function test_other_users_cannot_change_modules(): void
    {
        (require base_path(self::MODULES_MIGRATION))->up();
        $business = Business::create(['user_id' => $this->makeUser()->id, 'name' => 'Joyce', 'type' => 'boutique', 'modules' => ['orders']]);

        Sanctum::actingAs($this->makeUser());
        $this->patchJson("/api/v1/businesses/{$business->id}", ['modules' => []])->assertForbidden();

        $this->assertSame(['orders'], $business->fresh()->modules);
    }

    /**
     * @param  list<string>|null  $modules
     */
    private function insertTemplate(string $slug, string $type, ?array $modules = null): string
    {
        $id = (string) Str::uuid();

        DB::table('business_templates')->insert(array_filter([
            'id' => $id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'icon' => '•',
            'description' => $slug,
            'type' => $type,
            'default_greeting' => 'Bienvenue chez {nom} !',
            'default_ai_instructions' => 'Tu es l\'assistant de {nom}.',
            'default_modules' => $modules === null ? null : json_encode($modules),
        ], fn ($value) => $value !== null));

        return $id;
    }

    private function makeUser(): User
    {
        return User::forceCreate([
            'name' => 'Gérant',
            'email' => Str::random(8).'@example.test',
            'password' => 'x',
            'country' => 'BF',
        ]);
    }
}
