<?php

namespace Tests\Feature;

use App\Mail\OwnerNotificationMail;
use App\Models\Business;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * Module « catalog » : articles, variantes, stock, import et export, recherche de
 * l'assistant, commandes qui sortent du stock et alertes de stock faible.
 */
class CatalogTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test', 'app.frontend_url' => 'https://getcauseo.com']);
        $this->travelTo('2026-10-26 10:00:00');
        $this->createConversationSchema();
        Mail::fake();

        $this->owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Awa Couture', 'type' => 'boutique',
            'modules' => '["orders","catalog"]', 'delivery_enabled' => true, 'pickup_enabled' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $this->conversationId, 'business_id' => $id, 'customer_phone' => '22670000001', 'customer_name' => 'Mariam']);
    }

    // --- API du gérant -------------------------------------------------------------------------

    public function test_the_owner_creates_and_reads_a_product_with_its_variants(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/businesses/{$this->business->id}/products", [
            'name' => 'Robe wax Awa',
            'category' => 'Robes',
            'description' => 'Robe longue en wax',
            'base_price' => 12500,
            'variants' => [
                ['options' => ['Taille' => 'M', 'couleur' => 'rouge'], 'stock_quantity' => 5, 'sku' => 'RWA-M'],
                ['options' => ['taille' => 'L', 'couleur' => 'rouge'], 'price' => 13000, 'stock_quantity' => 1],
            ],
        ])->assertCreated();

        $response->assertJsonPath('product.name', 'Robe wax Awa')
            ->assertJsonPath('product.variants.0.options', ['taille' => 'M', 'couleur' => 'rouge'])
            ->assertJsonPath('product.variants.0.effective_price', 12500)
            ->assertJsonPath('product.variants.0.availability', 'available')
            ->assertJsonPath('product.variants.1.effective_price', 13000)
            ->assertJsonPath('product.variants.1.availability', 'low_stock');
        $this->assertArrayNotHasKey('embedding', $response->json('product'));

        // Le stock initial est un mouvement « manual ».
        $this->assertSame([5, 1], StockMovement::orderBy('id')->pluck('quantity')->all());
        $this->assertSame(['manual', 'manual'], StockMovement::orderBy('id')->pluck('reason')->all());

        $product = Product::sole();
        $this->getJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonCount(2, 'product.variants')
            ->assertJsonCount(2, 'movements');

        // Un article sans variantes en reçoit une, aux options vides.
        $simple = $this->postJson("/api/v1/businesses/{$this->business->id}/products", ['name' => 'Pagne wax 6 yards', 'base_price' => 7500])->assertCreated();
        $simple->assertJsonPath('product.variants.0.options', [])->assertJsonPath('product.variants.0.stock_quantity', null);
    }

    public function test_access_is_limited_to_the_owner_and_the_module(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->getJson("/api/v1/businesses/{$this->business->id}/products")->assertForbidden();
        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Piratée'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/businesses/{$this->business->id}/products", ['base_price' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Le nom de l\'article est obligatoire.', 'base_price' => 'Le prix ne peut pas être négatif.']);

        // Gratuit : catalogue consultable, pas modifiable.
        $this->assignPlan($this->business->id, Plan::FREE);
        $this->getJson("/api/v1/businesses/{$this->business->id}/products")->assertOk();
        $this->patchJson("/api/v1/variants/{$product->variants->first()->id}/stock", ['stock_quantity' => 9])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ne permet pas de gérer le catalogue'));

        $this->business->update(['modules' => ['orders']]);
        $this->getJson("/api/v1/businesses/{$this->business->id}/products")
            ->assertForbidden()
            ->assertJsonPath('message', "Le module Catalogue n'est pas activé pour cette entreprise.");
    }

    public function test_the_list_searches_and_filters_by_category_status_and_stock(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 0, 'sku' => 'RWA-M'], ['options' => ['taille' => 'L'], 'stock_quantity' => 8]], 'Robes');
        $chemise = $this->product('Chemise en lin', 9000, [['options' => ['taille' => 'L'], 'stock_quantity' => 2]], 'Chemises');
        $pagne = $this->product('Pagne wax', 7500, [['options' => []]], 'Pagnes');
        $pagne->update(['is_active' => false]);

        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business->id}/products";

        $this->assertNames(['Chemise en lin', 'Pagne wax', 'Robe wax Awa'], $this->getJson($url)->assertJsonPath('categories', ['Chemises', 'Pagnes', 'Robes']));
        $this->assertNames(['Pagne wax', 'Robe wax Awa'], $this->getJson("{$url}?q=WAX"));
        $this->assertNames(['Robe wax Awa'], $this->getJson("{$url}?q=rwa-m"));
        $this->assertNames(['Chemise en lin'], $this->getJson("{$url}?category=Chemises"));
        $this->assertNames(['Pagne wax'], $this->getJson("{$url}?is_active=0"));
        $this->assertNames(['Robe wax Awa'], $this->getJson("{$url}?stock=out"));
        $this->assertNames(['Chemise en lin'], $this->getJson("{$url}?stock=low"));
        $this->getJson("{$url}?stock=bientot")->assertUnprocessable()->assertJsonValidationErrors('stock');
    }

    public function test_updating_a_product_replaces_its_variants(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3], ['options' => ['taille' => 'L'], 'stock_quantity' => 2]]);
        [$m, $l] = $product->variants;

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/products/{$product->id}", [
            'base_price' => 13000,
            'variants' => [
                ['id' => $m->id, 'options' => ['taille' => 'M'], 'stock_quantity' => 6],
                ['options' => ['taille' => 'XL'], 'price' => 14000],
            ],
        ])->assertOk()->assertJsonPath('product.base_price', 13000);

        $this->assertSame(['M', 'XL'], $product->fresh()->variants->map(fn ($variant) => $variant->options['taille'])->all());
        $this->assertNull(ProductVariant::find($l->id));
        $this->assertSame(6, $m->fresh()->stock_quantity);
        $this->assertSame(3, StockMovement::where('variant_id', $m->id)->orderByDesc('id')->value('quantity'));

        $this->putJson("/api/v1/products/{$product->id}", ['variants' => [['options' => ['taille' => 'M']], ['options' => ['Taille' => 'm ']]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.1.options' => 'Deux variantes ont les mêmes options.']);
    }

    public function test_variants_are_generated_from_sizes_and_colors(): void
    {
        $product = $this->product('Chemise en lin', 9000, [['options' => []]]);

        Sanctum::actingAs($this->owner);
        $url = "/api/v1/products/{$product->id}/variants/generate";

        $this->postJson($url, ['options' => ['taille' => ['M', 'L'], 'couleur' => ['blanc', 'beige', 'blanc']], 'stock_quantity' => 4])
            ->assertOk()
            ->assertJsonPath('created', 4)
            ->assertJsonCount(4, 'product.variants');

        $variants = $product->fresh()->variants;
        $this->assertSame([['taille' => 'M', 'couleur' => 'blanc'], ['taille' => 'M', 'couleur' => 'beige'], ['taille' => 'L', 'couleur' => 'blanc'], ['taille' => 'L', 'couleur' => 'beige']], $variants->pluck('options')->all());
        $this->assertSame([4, 4, 4, 4], $variants->pluck('stock_quantity')->all());

        // Déjà présentes : rien de plus ; une nouvelle taille ajoute deux variantes.
        $this->postJson($url, ['options' => ['taille' => ['M', 'L'], 'couleur' => ['blanc', 'beige']]])->assertJsonPath('created', 0);
        $this->postJson($url, ['options' => ['taille' => ['XL'], 'couleur' => ['blanc', 'beige']]])->assertJsonPath('created', 2);
    }

    public function test_quick_stock_update_records_a_movement_and_alerts_once_below_the_threshold(): void
    {
        $this->business->update(['stock_alert_email' => true]);
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 6]]);
        $variant = $product->variants->first();

        Sanctum::actingAs($this->owner);
        $url = "/api/v1/variants/{$variant->id}/stock";

        $this->patchJson($url, ['adjustment' => -4])->assertOk()->assertJsonPath('variant.stock_quantity', 2)->assertJsonPath('variant.availability', 'low_stock');
        $this->patchJson($url, ['adjustment' => -1])->assertOk();
        $this->assertSame(1, Notification::where('type', 'stock')->count());

        $low = Notification::where('type', 'stock')->sole();
        $this->assertSame('Stock faible : Robe wax Awa', $low->title);
        $this->assertSame('Robe wax Awa (taille M) : il reste 2 pièces.', $low->body);
        $this->assertSame(['kind' => 'stock', 'level' => 'low_stock', 'product_id' => $product->id, 'variant_id' => $variant->id, 'action_path' => "/dashboard/catalog?product={$product->id}"], $low->data);

        // En rupture : la même alerte non lue est mise à jour.
        $this->patchJson($url, ['stock_quantity' => 0])->assertOk()->assertJsonPath('variant.availability', 'out_of_stock');
        $out = Notification::where('type', 'stock')->sole();
        $this->assertSame('Rupture de stock : Robe wax Awa', $out->title);
        $this->assertSame('out_of_stock', $out->data['level']);
        Mail::assertQueued(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('awa@example.test') && $mail->title === 'Rupture de stock : Robe wax Awa');

        $this->assertSame([6, -4, -1, -1], StockMovement::where('variant_id', $variant->id)->orderBy('id')->pluck('quantity')->all());
        $this->assertSame($this->owner->id, (int) StockMovement::where('variant_id', $variant->id)->orderByDesc('id')->value('user_id'));

        // Stock non suivi : null.
        $this->patchJson($url, ['stock_quantity' => null, 'adjustment' => null])->assertUnprocessable();
        $this->patchJson($url, ['stock_quantity' => 0, 'low_stock_threshold' => 5])->assertOk()->assertJsonPath('variant.low_stock_threshold', 5);
    }

    public function test_stock_alerts_by_email_are_optional(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);
        app(CatalogService::class)->setStock($product->variants->first(), 0);

        $this->assertSame(1, Notification::where('type', 'stock')->count());
        Mail::assertNothingQueued();
    }

    // --- Import et export -----------------------------------------------------------------------

    public function test_csv_import_creates_and_updates_variants_and_reports_errors(): void
    {
        $existing = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M', 'couleur' => 'rouge'], 'stock_quantity' => 1, 'sku' => 'RWA-M']]);

        $csv = implode("\n", [
            'Produit;Catégorie;Description;Prix base;Actif;Prix variante;Stock;Seuil stock faible;SKU;Taille;Couleur',
            'Robe wax Awa;Robes;;;oui;;7;;RWA-M;M;rouge',
            'robe WAX awa;;;;;13 000;3;1;;L;rouge',
            'Chemise en lin;Chemises;Manches courtes;9 000 FCFA;oui;;;;;M;blanc',
            'Chemise en lin;;;;;;5;;;L;blanc',
            ';Robes;;5000;;;;;;S;',
            'Pagne wax;Pagnes;;sept mille;;;;;;;',
            'Sac en cuir;Sacs;;15000;non;;2,5;;;;',
        ]);

        Sanctum::actingAs($this->owner);
        $response = $this->postJson("/api/v1/businesses/{$this->business->id}/products/import", [
            'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
        ])->assertOk();

        $response->assertJsonPath('report.created_products', 1)
            ->assertJsonPath('report.created_variants', 3)
            ->assertJsonPath('report.updated_variants', 1)
            ->assertJsonPath('report.errors', [
                ['line' => 6, 'message' => 'Le nom du produit est obligatoire.'],
                ['line' => 7, 'message' => 'prix_base invalide : « sept mille » (un nombre positif, sans devise).'],
                ['line' => 8, 'message' => 'stock invalide : « 2,5 » (un nombre entier).'],
            ]);

        $robe = $existing->fresh()->load('variants');
        $this->assertSame('Robes', $robe->category);
        $this->assertSame([7, 3], $robe->variants->pluck('stock_quantity')->all());
        $this->assertSame([null, 13000.0], $robe->variants->pluck('price')->all());
        $this->assertSame(['import'], StockMovement::where('variant_id', $robe->variants[0]->id)->orderByDesc('id')->limit(1)->pluck('reason')->all());

        $chemise = Product::where('name', 'Chemise en lin')->with('variants')->sole();
        $this->assertSame(9000.0, $chemise->base_price);
        $this->assertSame([['taille' => 'M', 'couleur' => 'blanc'], ['taille' => 'L', 'couleur' => 'blanc']], $chemise->variants->pluck('options')->all());
        $this->assertSame([null, 5], $chemise->variants->pluck('stock_quantity')->all());
        $this->assertSame(0, Product::where('name', 'Sac en cuir')->count());
    }

    public function test_excel_export_can_be_reimported_and_the_template_is_downloadable(): void
    {
        $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M', 'couleur' => 'rouge'], 'stock_quantity' => 4, 'sku' => 'RWA-M']], 'Robes');

        Sanctum::actingAs($this->owner);
        $export = $this->get("/api/v1/businesses/{$this->business->id}/products/export")->assertOk();
        $this->assertStringContainsString('catalogue.xlsx', $export->headers->get('Content-Disposition'));

        $rows = $this->xlsxRows($export->getFile()->getPathname());
        $this->assertSame(['produit', 'categorie', 'description', 'prix_base', 'actif', 'prix_variante', 'stock', 'seuil_stock_faible', 'sku', 'taille', 'couleur'], $rows[0]);
        $this->assertSame(['Robe wax Awa', 'Robes', '', '12500', 'oui', '', '4', '2', 'RWA-M', 'M', 'rouge'], array_map('strval', $rows[1]));

        // Modifier le stock dans Excel puis réimporter.
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($rows[0]));
        $writer->addRow(Row::fromValues([...array_slice($rows[1], 0, 6), 10, ...array_slice($rows[1], 7)]));
        $writer->close();

        $this->postJson("/api/v1/businesses/{$this->business->id}/products/import", [
            'file' => new UploadedFile($path, 'catalogue.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->assertJsonPath('report.updated_variants', 1)->assertJsonPath('report.errors', []);
        $this->assertSame(10, ProductVariant::sole()->stock_quantity);

        $template = $this->get("/api/v1/businesses/{$this->business->id}/products/import-template?format=csv")->assertOk();
        $content = file_get_contents($template->getFile()->getPathname());
        $this->assertStringContainsString('produit;categorie;description;prix_base;actif;prix_variante;stock;seuil_stock_faible;sku;taille;couleur', $content);
        $this->assertStringContainsString('"Robe wax Awa";Robes', $content);

        $this->postJson("/api/v1/businesses/{$this->business->id}/products/import", ['file' => UploadedFile::fake()->create('photo.png', 10, 'image/png')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    // --- Assistant --------------------------------------------------------------------------------

    public function test_the_assistant_searches_the_catalog_and_never_sees_exact_stock(): void
    {
        $this->product('Robe wax Awa', 12500, [
            ['options' => ['taille' => 'M', 'couleur' => 'rouge'], 'stock_quantity' => 7],
            ['options' => ['taille' => 'L', 'couleur' => 'rouge'], 'stock_quantity' => 1, 'price' => 13000],
            ['options' => ['taille' => 'L', 'couleur' => 'bleu'], 'stock_quantity' => 0],
        ], 'Robes', 'Robe longue en wax');
        $this->product('Chemise en lin', 9000, [['options' => ['taille' => 'L']]], 'Chemises');

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('search_catalog', ['query' => 'robe wax', 'size' => 'L']))
            ->push($this->text('La robe wax Awa en L rouge est à 13 000 FCFA, ce sont les dernières pièces. En bleu, elle est en rupture.'))]);

        $result = $this->service()->answer($this->business, 'Vous avez la robe wax en L ?', $this->conversationId);

        $this->assertStringContainsString('dernières pièces', $result['answer']);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertContains('search_catalog', array_column($requests[0]['tools'], 'name'));
        $this->assertStringContainsString('# Catalogue et stock', $this->systemPrompt($requests[0]));
        $this->assertStringContainsString('variant_id', json_encode(collect($requests[0]['tools'])->firstWhere('name', 'create_order')));

        $toolResult = json_decode(collect($requests[1]['messages'])->last()['content'][0]['content'], true);
        $this->assertSame(['Robe wax Awa'], array_column($toolResult['results'], 'name'));
        $variants = $toolResult['results'][0]['variants'];
        $this->assertSame([['taille' => 'L', 'couleur' => 'rouge'], ['taille' => 'L', 'couleur' => 'bleu']], array_column($variants, 'options'));
        $this->assertSame(['dernières pièces', 'en rupture'], array_column($variants, 'availability'));
        $this->assertSame([13000, 12500], array_map('intval', array_column($variants, 'price')));
        $this->assertStringNotContainsString('stock_quantity', json_encode($toolResult));

        // Couleur et prix maximal filtrent les variantes ; rien ne correspond : le dire.
        $catalog = app(CatalogService::class);
        $this->assertSame([['taille' => 'M', 'couleur' => 'rouge'], ['taille' => 'L', 'couleur' => 'rouge']], array_column($catalog->search($this->business, 'robe', ['color' => 'Rouge'])[0]['variants'], 'options'));
        $this->assertSame(['Chemise en lin'], array_column($catalog->search($this->business, 'robe chemise', ['max_price' => 10000]), 'name'));
        $this->assertSame([], $catalog->search($this->business, 'sac en cuir'));
    }

    public function test_the_tool_is_not_offered_without_active_products(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->text('Avec plaisir !'))]);

        $this->service()->answer($this->business, 'Vous avez des robes ?', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertNotContains('search_catalog', array_column($request['tools'], 'name'));
        $this->assertStringNotContainsString('# Catalogue et stock', $this->systemPrompt($request));
    }

    public function test_an_order_with_variant_id_uses_the_catalog_price_and_takes_the_stock(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);
        $variant = $product->variants->first();

        $this->askForOrder([['variant_id' => $variant->id, 'name' => 'robe', 'quantity' => 2, 'unit_price' => 9999]], 'Votre commande est enregistrée ✅');

        $order = Order::sole();
        $this->assertSame([['name' => 'Robe wax Awa', 'options' => 'taille M', 'quantity' => 2, 'unit_price' => 12500, 'variant_id' => $variant->id]], $order->items);
        $this->assertSame(25000.0, $order->total_amount);
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $movement = StockMovement::where('reason', 'order')->sole();
        $this->assertSame([-2, $order->id], [$movement->quantity, $movement->order_id]);
        $this->assertSame('low_stock', Notification::where('type', 'stock')->sole()->data['level']);
    }

    public function test_order_lines_with_a_variant_link_to_their_product(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);
        $variant = $product->variants->first();
        $this->askForOrder([
            ['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500],
            ['name' => 'Ceinture', 'quantity' => 1, 'unit_price' => 2000],
        ], 'Votre commande est enregistrée ✅');
        $order = Order::sole();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('order.items.0.product_id', $product->id)
            ->assertJsonMissingPath('order.items.1.product_id');
        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")->assertJsonPath('data.0.items.0.product_id', $product->id);
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertJsonPath('order.items.0.product_id', $product->id);

        // Rien n'est enregistré : la commande garde ses lignes d'origine.
        $this->assertArrayNotHasKey('product_id', $order->fresh()->items[0]);

        // Article supprimé : la ligne reste sans lien.
        $product->delete();
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonMissingPath('order.items.0.product_id');
    }

    public function test_an_out_of_stock_variant_is_refused(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 0]]);

        $this->askForOrder([['variant_id' => $product->variants->first()->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]], 'Désolée, la taille M est en rupture.');

        $this->assertStringContainsString('en rupture de stock', $this->lastToolResult());
        $this->assertSame(0, Order::count());
    }

    public function test_a_quantity_above_the_stock_is_refused_without_giving_the_count(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'L'], 'stock_quantity' => 3]]);
        $variant = $product->variants->first();

        $this->askForOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 5, 'unit_price' => 12500]], 'Il ne reste pas assez de pièces en L.');

        $error = $this->lastToolResult();
        $this->assertStringContainsString('Pas assez de pièces', $error);
        $this->assertStringNotContainsString('3', $error);
        $this->assertSame(0, Order::count());
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('reason', 'order')->count());
    }

    public function test_cancelling_an_order_puts_the_stock_back_once(): void
    {
        $product = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);
        $variant = $product->variants->first();
        $this->askForOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 2, 'unit_price' => 12500]], 'Votre commande est enregistrée ✅');
        $order = Order::sole();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(3, $variant->fresh()->stock_quantity);

        // Réactivée : les articles sortent à nouveau du stock.
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(1, $variant->fresh()->stock_quantity);

        $this->assertSame([['order', -2], ['order_cancelled', 2], ['order', -2]], StockMovement::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($m) => [$m->reason, $m->quantity])->all());
    }

    // --- Outils -------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function product(string $name, float $price, array $variants, ?string $category = null, ?string $description = null): Product
    {
        return app(CatalogService::class)->saveProduct($this->business, ['name' => $name, 'base_price' => $price, 'category' => $category, 'description' => $description], null, $variants);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function askForOrder(array $items, string $reply): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('create_order', ['items' => $items, 'fulfillment_type' => 'pickup', 'customer_name' => 'Mariam', 'payment_method' => 'Orange Money']))
            ->push($this->text($reply))]);

        $this->service()->answer($this->business->fresh(), 'Oui je confirme', $this->conversationId);
    }

    /** Contenu du dernier tool_result envoyé à Claude. */
    private function lastToolResult(): string
    {
        return (string) collect(Http::recorded()[1][0]->data()['messages'])->last()['content'][0]['content'];
    }

    private function service(): AIResponseService
    {
        return new FixedContextAIResponseService('Boutique Awa Couture, Ouagadougou.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toolUse(string $name, array $input): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $name, 'input' => $input],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param  list<string>  $names
     */
    private function assertNames(array $names, \Illuminate\Testing\TestResponse $response): void
    {
        $this->assertSame($names, $response->assertOk()->json('data.*.name'));
    }

    /**
     * @return list<list<mixed>>
     */
    private function xlsxRows(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }
}
