<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Reprise des données existantes par les migrations de la phase 1 « stock et ventes », puis
 * retour arrière sans perte. Les données ont la forme de celles de production : lignes JSON avec
 * ou sans variant_id, options en texte ou en liste, suppléments, statuts variés, variantes en
 * doublon (options, SKU).
 */
class StockAndSalesMigrationTest extends TestCase
{
    use CreatesConversationSchema;

    private const MIGRATIONS = [
        '2026_11_20_100000_create_order_items_and_payments.php',
        '2026_11_20_110000_stock_movements_reasons_and_catalog_archive.php',
        '2026_11_20_120000_add_order_expiry_hours_to_businesses.php',
    ];

    private string $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->createConversationSchema();

        // Base telle qu'avant la phase 1.
        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $this->migration($migration)->down();
        }

        $owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        $this->businessId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $this->businessId, 'user_id' => $owner->id, 'name' => 'Awa Couture', 'type' => 'boutique', 'modules' => '["orders","catalog"]']);
    }

    public function test_existing_orders_and_variants_are_taken_over_and_the_rollback_loses_nothing(): void
    {
        $this->assertFalse(Schema::hasTable('order_items'));

        $robe = $this->product('Robe wax Awa');
        $m = $this->variant($robe, ['taille' => 'M'], 'RWA-M', 3);
        $mDuplicate = $this->variant($robe, ['Taille' => ' m '], null, 1);
        $other = $this->product('Chemise');
        $skuDuplicate = $this->variant($other, ['taille' => 'L'], 'rwa-m', 2);

        $orders = [
            'paid' => $this->order('paid', 'Orange Money', [['name' => 'Robe wax Awa', 'options' => 'taille M', 'quantity' => 2, 'unit_price' => 12500, 'variant_id' => $m]], 25000),
            'delivered' => $this->order('delivered', 'Paiement à la livraison', [['name' => 'Pagne', 'options' => ['bleu', 'grand'], 'quantity' => 1, 'unit_price' => 7500], ['name' => 'Frais de livraison', 'quantity' => 1, 'unit_price' => 1000]], 8500),
            'new' => $this->order('new', 'Moov', [['name' => 'Riz gras', 'options' => null, 'quantity' => 1, 'unit_price' => 2500, 'extras' => [['name' => 'Fromage', 'price' => 500]]]], 2500),
            'cancelled' => $this->order('cancelled', null, [['name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500, 'variant_id' => (string) Str::uuid()]], 12500),
            'empty' => $this->order('confirmed', 'Wave', [], 0),
        ];
        $movement = DB::table('stock_movements')->insertGetId(['variant_id' => $m, 'quantity' => -2, 'reason' => 'order', 'order_id' => $orders['paid'], 'created_at' => now()]);
        $before = DB::table('orders')->orderBy('id')->get()->keyBy('id');

        foreach (self::MIGRATIONS as $migration) {
            $this->migration($migration)->up();
        }

        // Lignes reprises, avec le lien vers l'article quand la variante existe encore.
        $lines = DB::table('order_items')->orderBy('order_id')->orderBy('position')->get()->groupBy('order_id');
        $this->assertCount(4, $lines, 'Une commande sans ligne JSON n\'a pas de ligne en table.');
        $paidLine = $lines[$orders['paid']]->sole();
        $this->assertSame([$m, $robe, 'taille M', 2, 12500.0, 12500.0, 25000.0], [$paidLine->variant_id, $paidLine->product_id, $paidLine->options, (int) $paidLine->quantity, (float) $paidLine->catalog_unit_price, (float) $paidLine->unit_price, (float) $paidLine->line_total]);
        $this->assertSame(['bleu, grand', null], $lines[$orders['delivered']]->pluck('options')->all());
        $this->assertNull($lines[$orders['delivered']]->first()->catalog_unit_price);
        $this->assertSame([['name' => 'Fromage', 'price' => 500]], json_decode($lines[$orders['new']]->sole()->extras, true));
        // Variante disparue : la ligne reste, sans lien.
        $this->assertNull($lines[$orders['cancelled']]->sole()->variant_id);

        // Commandes payées ou livrées : un versement du total, au moyen déduit du texte.
        $payments = DB::table('order_payments')->get()->keyBy('order_id');
        $this->assertCount(2, $payments);
        $this->assertSame(['orange_money', 25000.0], [$payments[$orders['paid']]->method, (float) $payments[$orders['paid']]->amount]);
        $this->assertSame('cash', $payments[$orders['delivered']]->method);
        $after = DB::table('orders')->get()->keyBy('id');
        $this->assertSame(['paid', 25000.0], [$after[$orders['paid']]->payment_status, (float) $after[$orders['paid']]->amount_paid]);
        $this->assertSame('unpaid', $after[$orders['new']]->payment_status);
        $this->assertNotNull($after[$orders['cancelled']]->cancelled_at);
        $this->assertSame('whatsapp_ai', $after[$orders['new']]->source);

        // Rien n'est modifié dans les colonnes d'origine.
        foreach ($before as $id => $order) {
            $this->assertSame([$order->items, $order->status, $order->total_amount, $order->customer_phone], [$after[$id]->items, $after[$id]->status, $after[$id]->total_amount, $after[$id]->customer_phone]);
        }

        // Doublons gardés et signalés : clé d'options distincte, SKU suffixé.
        $variants = DB::table('product_variants')->get()->keyBy('id');
        $this->assertNotSame($variants[$m]->options_key, $variants[$mDuplicate]->options_key);
        $this->assertSame([$this->businessId, null], [$variants[$m]->business_id, $variants[$m]->deleted_at]);
        $this->assertSame(['RWA-M', 'rwa-m-DOUBLON-2'], [$variants[$m]->sku, $variants[$skuDuplicate]->sku]);
        $this->assertSame($this->businessId, DB::table('stock_movements')->find($movement)->business_id);
        $this->assertSame(24, (int) DB::table('businesses')->value('order_expiry_hours'));

        // Retour arrière : tout est encore là.
        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $this->migration($migration)->down();
        }
        $this->assertFalse(Schema::hasTable('order_items'));
        $this->assertFalse(Schema::hasColumn('orders', 'source'));
        $rolledBack = DB::table('orders')->get()->keyBy('id');
        foreach ($before as $id => $order) {
            $this->assertSame([$order->items, $order->status, $order->total_amount, $order->customer_phone], [$rolledBack[$id]->items, $rolledBack[$id]->status, $rolledBack[$id]->total_amount, $rolledBack[$id]->customer_phone]);
        }
        $this->assertSame(3, DB::table('product_variants')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());

        // Et de nouveau en avant : la reprise est rejouable.
        foreach (self::MIGRATIONS as $migration) {
            $this->migration($migration)->up();
        }
        $this->assertSame(5, DB::table('order_items')->count());
    }

    public function test_the_rollback_keeps_counter_sales_without_phone_and_new_movement_reasons(): void
    {
        foreach (self::MIGRATIONS as $migration) {
            $this->migration($migration)->up();
        }

        $robe = $this->product('Robe wax Awa');
        $variant = $this->variant($robe, ['taille' => 'M'], null, 5);
        $sale = $this->order('delivered', 'Espèces', [['name' => 'Robe wax Awa', 'quantity' => 1, 'unit_price' => 12000, 'variant_id' => $variant]], 12000, phone: null);
        DB::table('stock_movements')->insert([
            ['business_id' => $this->businessId, 'variant_id' => $variant, 'quantity' => -1, 'reason' => 'counter_sale', 'order_id' => $sale, 'created_at' => now()],
            ['business_id' => $this->businessId, 'variant_id' => $variant, 'quantity' => 4, 'reason' => 'restock', 'order_id' => null, 'created_at' => now()],
            ['business_id' => $this->businessId, 'variant_id' => $variant, 'quantity' => 2, 'reason' => 'order_expired', 'order_id' => null, 'created_at' => now()],
        ]);

        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $this->migration($migration)->down();
        }

        $this->assertSame('', DB::table('orders')->where('id', $sale)->value('customer_phone'));
        $this->assertSame(['order', 'manual', 'order_cancelled'], DB::table('stock_movements')->orderBy('id')->pluck('reason')->all());
        $this->assertSame([-1, 4, 2], DB::table('stock_movements')->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all());
    }

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}");
    }

    private function product(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert(['id' => $id, 'business_id' => $this->businessId, 'name' => $name, 'base_price' => 12500, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function variant(string $productId, array $options, ?string $sku, ?int $stock): string
    {
        $id = (string) Str::uuid();
        DB::table('product_variants')->insert(['id' => $id, 'product_id' => $productId, 'options' => json_encode($options), 'sku' => $sku, 'stock_quantity' => $stock, 'low_stock_threshold' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->travel(1)->seconds();

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function order(string $status, ?string $paymentMethod, array $items, float $total, ?string $phone = '22670000001'): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id, 'business_id' => $this->businessId, 'customer_phone' => $phone, 'customer_name' => 'Mariam',
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'total_amount' => $total, 'payment_method' => $paymentMethod,
            'status' => $status, 'fulfillment_type' => 'pickup', 'created_at' => now()->subDay(), 'updated_at' => now()->subHours(2),
        ]);

        return $id;
    }
}
