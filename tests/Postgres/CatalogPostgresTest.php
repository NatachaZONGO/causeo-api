<?php

namespace Tests\Postgres;

use App\Models\Business;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Catalog\CatalogService;
use App\Services\Embedding\EmbeddingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Module « catalog » sur PostgreSQL : RLS, contraintes, recherche par embedding (pgvector)
 * et filtres de la liste.
 */
class CatalogPostgresTest extends PostgresTestCase
{
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        // Embedding factice (sans appel à Gemini) : « boubou » et « fête » sont proches, le reste ailleurs.
        config(['services.gemini.api_key' => 'test-key']);
        $this->app->instance(EmbeddingService::class, new class extends EmbeddingService
        {
            public function __construct() {}

            public function embed(string $text): array
            {
                $vector = array_fill(0, 1536, 0.0);
                $vector[preg_match('/boubou|f[êe]te/iu', $text) === 1 ? 0 : 1] = 1.0;

                return $vector;
            }
        });

        $userId = $this->user();
        $this->business = Business::findOrFail($this->business($userId));
        $this->business->update(['modules' => ['orders', 'catalog']]);
        app(BillingService::class)->assignPlan($this->business, Plan::bySlug(Plan::INTERNAL));
    }

    public function test_tables_have_row_level_security_and_constraints(): void
    {
        foreach (['products', 'product_variants', 'stock_movements'] as $table) {
            $this->assertTrue((bool) DB::scalar('SELECT relrowsecurity FROM pg_class WHERE relname = ?', [$table]), $table);
        }

        $product = $this->catalog()->saveProduct($this->business, ['name' => 'Robe wax Awa', 'base_price' => 12500], null, [['options' => ['taille' => 'M'], 'stock_quantity' => 1]]);
        $this->catalog()->setStock($product->variants->first(), 0);
        $this->assertSame('out_of_stock', Notification::where('type', 'stock')->sole()->data['level']);

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert(['variant_id' => $product->variants->first()->id, 'quantity' => 1, 'reason' => 'vol', 'created_at' => now()]);
    }

    public function test_search_finds_products_by_meaning_with_pgvector(): void
    {
        $this->catalog()->saveProduct($this->business, ['name' => 'Boubou brodé', 'category' => 'Hommes', 'base_price' => 25000], null, [['options' => ['taille' => 'XL'], 'stock_quantity' => 4]]);
        $this->catalog()->saveProduct($this->business, ['name' => 'Chemise en lin', 'base_price' => 9000], null, [['options' => ['taille' => 'L']]]);

        $this->assertNotNull(DB::scalar('SELECT embedding FROM products WHERE name = ?', ['Boubou brodé']));

        // Aucun mot en commun : seule la similarité des embeddings le trouve.
        $results = $this->catalog()->search($this->business, 'une tenue pour la fête');
        $this->assertSame(['Boubou brodé'], array_column($results, 'name'));
        $this->assertSame('disponible', $results[0]['variants'][0]['availability']);
    }

    public function test_the_list_filters_run_on_postgresql(): void
    {
        $this->catalog()->saveProduct($this->business, ['name' => 'Robe wax Awa', 'category' => 'Robes', 'base_price' => 12500], null, [
            ['options' => ['taille' => 'M'], 'stock_quantity' => 0, 'sku' => 'RWA-M'],
            ['options' => ['taille' => 'L'], 'stock_quantity' => 2],
        ]);
        $this->catalog()->saveProduct($this->business, ['name' => 'Chemise en lin', 'category' => 'Chemises', 'base_price' => 9000], null, [['options' => ['taille' => 'L'], 'stock_quantity' => 9]]);

        Sanctum::actingAs(User::findOrFail($this->business->user_id));
        $url = "/api/v1/businesses/{$this->business->id}/products";

        $this->assertSame(['Robe wax Awa'], $this->getJson("{$url}?stock=out")->assertOk()->json('data.*.name'));
        $this->assertSame(['Robe wax Awa'], $this->getJson("{$url}?stock=low")->assertOk()->json('data.*.name'));
        $this->assertSame(['Robe wax Awa'], $this->getJson("{$url}?q=rwa")->assertOk()->json('data.*.name'));
        $this->assertSame(['Chemises', 'Robes'], $this->getJson($url)->assertOk()->json('categories'));
        $this->assertSame(2, Product::count());
    }

    private function catalog(): CatalogService
    {
        return app(CatalogService::class);
    }
}
