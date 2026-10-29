<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\WhatsAppAlertController;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\AppointmentService;
use App\Services\Catalog\CatalogService;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * Un seul moteur de catalogue, adapté à l'activité : boutique (articles, stock), restaurant
 * (menu, plats épuisés aujourd'hui, suppléments payants, sur place) et prestations (durée et
 * prix repris par les rendez-vous). Activation des alertes WhatsApp en un clic, avec consentement.
 */
class ActivityProfileTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-29 10:00:00');
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_12_100000_add_whatsapp_alerts_to_users.php'))->up();
        (require base_path('database/migrations/2026_10_29_110000_add_whatsapp_alerts_consent_version.php'))->up();
        Mail::fake();

        $this->owner = User::forceCreate(['name' => 'Fatou', 'email' => 'fatou@example.test', 'password' => 'x']);
    }

    // --- Profils et vocabulaire -----------------------------------------------------------------

    public function test_profiles_and_their_vocabulary_are_exposed(): void
    {
        $profiles = $this->getJson('/api/v1/activity-profiles')->assertOk()->json('data');

        $this->assertSame(['boutique', 'restaurant', 'services', 'hotel', 'formation', 'immobilier'], array_column($profiles, 'key'));
        $this->assertSame([true, true, true, false, false, false], array_column($profiles, 'available'));
        $this->assertSame(['Catalogue', 'Menu', 'Prestations'], array_slice(array_column($profiles, 'module_label'), 0, 3));
        $this->assertSame(['Entrées', 'Plats', 'Desserts', 'Boissons', 'Formules'], $profiles[1]['categories']);
        $this->assertSame([['key' => 'supplement', 'label' => 'Supplément', 'priced' => true]], array_values(array_filter($profiles[1]['option_types'], fn ($type) => $type['priced'])));
        $this->assertSame(['quantity', 'daily', 'none'], array_column(array_slice($profiles, 0, 3), 'tracking'));
        $this->assertSame(['orders', 'orders', 'appointments'], array_column(array_slice($profiles, 0, 3), 'linked_module'));
    }

    public function test_the_owner_changes_the_profile_of_his_business(): void
    {
        $business = $this->business('boutique');
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/businesses/{$business->id}", ['activity_profile' => 'restaurant', 'dine_in_enabled' => true])
            ->assertOk()
            ->assertJsonPath('business.activity.key', 'restaurant')
            ->assertJsonPath('business.activity.module_label', 'Menu')
            ->assertJsonPath('business.activity.item_plural', 'plats')
            ->assertJsonPath('business.dine_in_enabled', true);

        $this->putJson("/api/v1/businesses/{$business->id}", ['activity_profile' => 'hotel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['activity_profile' => 'Activité inconnue. Activités disponibles : boutique, restaurant, services.']);
    }

    // --- Boutique --------------------------------------------------------------------------------

    public function test_a_boutique_keeps_its_stock_in_quantities(): void
    {
        $business = $this->business('boutique');
        $robe = $this->product($business, ['name' => 'Robe wax Awa', 'base_price' => 12500], [['options' => ['taille' => 'M'], 'stock_quantity' => 0]]);

        $this->assertSame(0, $robe->variants->first()->stock_quantity);
        $this->assertSame('en rupture', app(CatalogService::class)->search($business, 'robe wax')[0]['variants'][0]['availability']);
    }

    // --- Restaurant ------------------------------------------------------------------------------

    public function test_a_restaurant_dish_with_paid_extras_and_daily_availability(): void
    {
        $business = $this->business('restaurant', ['dine_in_enabled' => true, 'pickup_enabled' => true]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/v1/businesses/{$business->id}/products", [
            'name' => 'Poulet braisé', 'base_price' => 2500, 'category' => 'Plats', 'preparation_minutes' => 20,
            'extras' => [['name' => 'Fromage', 'price' => 500], ['name' => 'Alloco', 'price' => 300]],
            'variants' => [['options' => ['portion' => 'demi'], 'stock_quantity' => 7], ['options' => ['portion' => 'entier'], 'price' => 4500]],
        ])->assertCreated()
            ->assertJsonPath('product.extras.0', ['name' => 'Fromage', 'price' => 500])
            ->assertJsonPath('product.preparation_minutes', 20)
            // Pas de stock en quantité au restaurant.
            ->assertJsonPath('product.variants.0.stock_quantity', null)
            ->json('product.id');

        $found = app(CatalogService::class)->search($business, 'poulet braisé')[0];
        $this->assertSame('disponible aujourd\'hui', $found['variants'][0]['availability']);
        $this->assertSame('environ 20 min', $found['preparation']);
        $this->assertSame([['name' => 'Fromage', 'price' => 500], ['name' => 'Alloco', 'price' => 300]], $found['extras']);

        $this->patchJson("/api/v1/products/{$id}/availability", ['available' => false])
            ->assertOk()->assertJsonPath('message', 'Le plat est marqué épuisé.')->assertJsonPath('product.sold_out', true);
        $this->assertSame('épuisé aujourd\'hui', app(CatalogService::class)->search($business, 'poulet braisé')[0]['variants'][0]['availability']);

        // Le lendemain matin, le plat redevient disponible de lui-même.
        $this->travelTo('2026-10-30 07:00:00');
        $this->assertFalse(Product::find($id)->sold_out);

        // Sans remise à disponible chaque matin, il reste épuisé jusqu'au rappel du gérant.
        $business->update(['catalog_daily_reset' => false]);
        $this->patchJson("/api/v1/products/{$id}/availability", ['available' => false])->assertOk();
        $this->travelTo('2026-10-31 07:00:00');
        $this->assertTrue(Product::find($id)->sold_out);
        $this->patchJson("/api/v1/products/{$id}/availability", ['available' => true])->assertOk()->assertJsonPath('message', 'Le plat est de nouveau disponible.');
        $this->assertFalse(Product::find($id)->sold_out);
    }

    public function test_paid_extras_are_added_to_the_order_total_and_dine_in_is_a_mode(): void
    {
        $business = $this->business('restaurant', ['dine_in_enabled' => true]);
        $poulet = $this->product($business, ['name' => 'Poulet braisé', 'base_price' => 2500, 'extras' => [['name' => 'Fromage', 'price' => 500]]], [['options' => ['portion' => 'demi']]]);
        $conversation = Conversation::create(['business_id' => $business->id, 'customer_phone' => '22670000001']);

        $order = app(OrderService::class)->createFromAi($business, $conversation, [
            'items' => [['name' => 'Poulet', 'variant_id' => $poulet->variants->first()->id, 'quantity' => 2, 'unit_price' => 2500, 'extras' => ['fromage']]],
            'fulfillment_type' => 'dine_in', 'customer_name' => 'Kadi', 'payment_method' => 'Espèces',
        ]);

        $this->assertSame(6000.0, (float) $order->total_amount);
        $this->assertSame('dine_in', $order->fulfillment_type);
        $this->assertSame('portion demi, supplément fromage (+500 FCFA)', $order->items[0]['options']);
        $this->assertSame([['name' => 'Fromage', 'price' => 500]], $order->items[0]['extras']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('le supplément « bacon » n\'existe pas pour « Poulet braisé ». Suppléments proposés : Fromage (+500 FCFA).');
        app(OrderService::class)->createFromAi($business, $conversation, [
            'items' => [['name' => 'Poulet', 'variant_id' => $poulet->variants->first()->id, 'quantity' => 1, 'unit_price' => 2500, 'extras' => ['bacon']]],
            'fulfillment_type' => 'dine_in', 'customer_name' => 'Kadi', 'payment_method' => 'Espèces',
        ]);
    }

    public function test_the_assistant_speaks_of_dishes_and_the_menu(): void
    {
        $business = $this->business('restaurant', ['dine_in_enabled' => true, 'pickup_enabled' => true]);
        $this->product($business, ['name' => 'Poulet braisé', 'base_price' => 2500], [['options' => []]]);
        $request = $this->ask($business, 'Vous avez du poulet ?');

        $this->assertStringContainsString("# Menu du restaurant\nCe catalogue est le menu du restaurant", $request['system'][0]['text']);
        $this->assertStringContainsString('un plat est « disponible aujourd\'hui » ou « épuisé aujourd\'hui », jamais « en rupture de stock »', $request['system'][0]['text']);
        $this->assertStringContainsString("- à emporter (pickup)\n- sur place (dine_in)", $request['system'][0]['text']);
        $tools = collect($request['tools'])->keyBy('name');
        $this->assertStringStartsWith('Cherche des plats dans le menu du restaurant.', $tools['search_catalog']['description']);
        $this->assertArrayHasKey('extras', $tools['create_order']['input_schema']['properties']['items']['items']['properties']);
        $this->assertSame(['pickup', 'dine_in'], $tools['create_order']['input_schema']['properties']['fulfillment_type']['enum']);
    }

    // --- Prestations -----------------------------------------------------------------------------

    public function test_a_service_has_a_duration_and_a_price_reused_by_the_appointment(): void
    {
        $business = $this->business('salon_beaute', ['modules' => '["appointments","catalog"]']);
        $tresses = $this->product($business, ['name' => 'Tresses africaines', 'base_price' => 15000, 'duration_minutes' => 120, 'category' => 'Coiffure'], [
            ['options' => ['niveau' => 'simple'], 'stock_quantity' => 3],
            ['options' => ['niveau' => 'avec mèches', 'duree' => '180'], 'price' => 22000],
        ]);

        $this->assertNull($tresses->variants->first()->stock_quantity);
        $found = app(CatalogService::class)->search($business, 'tresses')[0];
        $this->assertSame('2 h', $found['duration']);
        $this->assertSame('2 h', $found['variants'][0]['duration']);
        $this->assertSame('3 h', $found['variants'][1]['duration']);
        $this->assertArrayNotHasKey('availability', $found['variants'][0]);

        $conversation = Conversation::create(['business_id' => $business->id, 'customer_phone' => '22670000001']);
        $appointment = app(AppointmentService::class)->createFromAi($business, $conversation, [
            'service' => 'des tresses', 'product_id' => $tresses->id, 'variant_id' => $tresses->variants[1]->id,
            'requested_date' => '2026-10-31', 'requested_time' => '15h', 'customer_name' => 'Awa', 'price' => 1000,
        ]);

        $this->assertSame('Tresses africaines (niveau avec mèches, duree 180)', $appointment->service);
        $this->assertSame(22000.0, $appointment->price);
        $this->assertSame(180, $appointment->duration_minutes);
        $this->assertSame($tresses->id, $appointment->product_id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('product_id inconnu.');
        app(AppointmentService::class)->createFromAi($business, $conversation, [
            'service' => 'Coupe', 'product_id' => (string) Str::uuid(), 'requested_date' => '2026-10-31', 'requested_time' => '16h', 'customer_name' => 'Awa',
        ]);
    }

    public function test_the_assistant_speaks_of_services_and_books_them_by_product(): void
    {
        $business = $this->business('salon_beaute', ['modules' => '["appointments","catalog"]']);
        $this->product($business, ['name' => 'Tresses africaines', 'base_price' => 15000, 'duration_minutes' => 120], [['options' => []]]);
        $request = $this->ask($business, 'Les tresses durent combien de temps ?');

        $this->assertStringContainsString('« cette prestation dure 2 h et coûte 15 000 FCFA »', $request['system'][0]['text']);
        $tools = collect($request['tools'])->keyBy('name');
        $this->assertStringStartsWith('Cherche des prestations dans la liste des services.', $tools['search_catalog']['description']);
        $this->assertArrayHasKey('product_id', $tools['create_appointment']['input_schema']['properties']);
        $this->assertStringContainsString('"duration":"2 h"', $request['messages'][0]['content']);
    }

    // --- Alertes WhatsApp en un clic --------------------------------------------------------------

    public function test_whatsapp_alerts_are_activated_in_one_click_only_with_explicit_consent(): void
    {
        if (! Schema::hasColumn('businesses', 'phone')) {
            Schema::table('businesses', fn (Blueprint $table) => $table->string('phone')->nullable());
        }
        $this->business('boutique', ['phone' => '+226 70 11 22 33']);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/me/whatsapp-alerts')
            ->assertOk()
            ->assertJsonPath('suggested_phone', '+22670112233')
            ->assertJsonPath('consent_text', WhatsAppAlertController::CONSENT_TEXT)
            ->assertJsonPath('whatsapp_alerts_enabled', false);

        $this->postJson('/api/v1/me/whatsapp-alerts/activate', ['notification_phone' => '+22670112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent' => 'Cochez la case pour accepter de recevoir les alertes WhatsApp de Causeo sur ce numéro.']);
        $this->assertFalse((bool) $this->owner->fresh()->whatsapp_alerts_enabled);

        $this->postJson('/api/v1/me/whatsapp-alerts/activate', ['notification_phone' => '+226 70 11 22 33', 'consent' => true])
            ->assertOk()
            ->assertJsonPath('notification_phone', '+22670112233')
            ->assertJsonPath('whatsapp_alerts_enabled', true)
            ->assertJsonPath('whatsapp_alerts_consented_at', now()->toIso8601String())
            ->assertJsonPath('whatsapp_alerts_consent_version', WhatsAppAlertController::CONSENT_VERSION);
    }

    // --- Outils -------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function business(string $type, array $attributes = []): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert($attributes + [
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Chez Fatou', 'type' => $type,
            'modules' => '["orders","catalog"]', 'delivery_enabled' => false,
        ]);
        $this->assignPlan($id);

        return Business::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $variants
     */
    private function product(Business $business, array $data, array $variants): Product
    {
        return app(CatalogService::class)->saveProduct($business, $data, null, $variants);
    }

    /**
     * Question posée à l'assistant dans une conversation ; renvoie la requête envoyée à Claude.
     *
     * @return array<string, mixed>
     */
    private function ask(Business $business, string $question): array
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Oui.']]])]);
        $conversation = Conversation::create(['business_id' => $business->id, 'customer_phone' => '22670000001']);

        (new FixedContextAIResponseService('Chez Fatou, Ouagadougou.'))->answer($business->fresh(), $question, $conversation->id);

        return Http::recorded()[0][0]->data();
    }
}
