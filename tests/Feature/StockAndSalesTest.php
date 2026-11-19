<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalog\CatalogService;
use App\Services\OrderService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Phase 1 « stock et ventes » : vente au comptoir (prix négocié, paiements partiels, crédit),
 * versements, passages de statut, expiration des commandes non confirmées, mouvements de stock
 * saisis, journal, archivage, résumé des ventes.
 */
class StockAndSalesTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
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

        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $conversationId, 'business_id' => $id, 'customer_phone' => '22670000001', 'customer_name' => 'Mariam']);
        $this->conversation = Conversation::findOrFail($conversationId);
    }

    // --- Vente au comptoir ------------------------------------------------------------------------

    public function test_a_counter_sale_with_a_negotiated_price_takes_the_stock_at_once(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M', 'couleur' => 'rouge'], 'stock_quantity' => 5]]);
        $variant = $robe->variants->first();
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->salesUrl(), [
            'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 11000]],
            'payment_method' => 'cash',
        ])->assertCreated();

        $response->assertJsonPath('order.source', 'counter')
            ->assertJsonPath('order.status', 'delivered')
            ->assertJsonPath('order.total_amount', 22000)
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.amount_paid', 22000)
            ->assertJsonPath('order.balance_due', 0)
            ->assertJsonPath('order.customer_phone', null)
            ->assertJsonPath('order.items.0.catalog_unit_price', 12500)
            ->assertJsonPath('order.items.0.unit_price', 11000)
            ->assertJsonPath('order.items.0.line_total', 22000)
            ->assertJsonPath('order.items.0.options', 'taille M, couleur rouge')
            ->assertJsonPath('order.payments.0.method', 'cash');

        $line = OrderItem::sole();
        $this->assertSame([12500.0, 11000.0, $robe->id, $variant->id], [$line->catalog_unit_price, $line->unit_price, $line->product_id, $line->variant_id]);
        $this->assertSame(3, $variant->fresh()->stock_quantity);

        $movement = StockMovement::where('reason', 'counter_sale')->sole();
        $this->assertSame([-2, 3, $line->order_id, $this->business->id], [$movement->quantity, $movement->balance_after, $movement->order_id, $movement->business_id]);

        // Copie de secours écrite en JSON pendant la transition.
        $this->assertSame(11000, Order::sole()->items[0]['unit_price']);
    }

    public function test_a_counter_sale_above_the_stock_is_refused_with_the_remaining_count(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'L'], 'stock_quantity' => 2]]);
        $pagne = $this->product('Pagne wax', 7500, [['options' => [], 'stock_quantity' => 0]]);
        Sanctum::actingAs($this->owner);

        $this->postJson($this->salesUrl(), [
            'items' => [['variant_id' => $robe->variants->first()->id, 'quantity' => 3]],
            'payment_method' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuffisant pour « Robe wax Awa taille L » : il en reste 2, vous en vendez 3.');

        $this->postJson($this->salesUrl(), [
            'items' => [['variant_id' => $pagne->variants->first()->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonPath('message', '« Pagne wax » est en rupture de stock.');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(2, $robe->variants->first()->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('reason', 'counter_sale')->count());
    }

    public function test_a_partial_payment_needs_the_customer_and_is_completed_by_later_payments(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 5]])->variants->first();
        Sanctum::actingAs($this->owner);

        $sale = ['items' => [['variant_id' => $variant->id, 'quantity' => 2]], 'payment_method' => 'orange_money', 'amount_paid' => 10000];

        $this->postJson($this->salesUrl(), $sale)->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'le nom et le téléphone du client sont obligatoires'));
        $this->assertSame(5, $variant->fresh()->stock_quantity);

        $order = $this->postJson($this->salesUrl(), $sale + ['customer_name' => 'Fatou', 'customer_phone' => '+225 07 08 09 10 11'])
            ->assertCreated()
            ->assertJsonPath('order.payment_status', 'partial')
            ->assertJsonPath('order.amount_paid', 10000)
            ->assertJsonPath('order.balance_due', 15000)
            ->assertJsonPath('order.customer_phone', '2250708091011')
            ->json('order');

        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['amount' => 20000, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le versement (20 000 FCFA) dépasse le reste à payer (15 000 FCFA).');

        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['amount' => 5000, 'method' => 'moov_money', 'note' => 'Deuxième versement'])
            ->assertCreated()
            ->assertJsonPath('order.payment_status', 'partial')
            ->assertJsonPath('order.balance_due', 10000);

        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['amount' => 10000, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('message', 'Versement enregistré : la commande est soldée.')
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonCount(3, 'order.payments');

        $this->assertSame(['orange_money', 'moov_money', 'cash'], OrderPayment::orderBy('id')->pluck('method')->all());
        $this->assertNotNull(Order::sole()->paid_at);
        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['amount' => 1, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonPath('message', 'Cette commande est déjà entièrement payée.');
    }

    public function test_a_credit_sale_records_nothing_paid_and_needs_the_customer(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 5]])->variants->first();
        Sanctum::actingAs($this->owner);

        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'credit', 'customer_name' => 'Fatou'])
            ->assertUnprocessable();

        $this->postJson($this->salesUrl(), [
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'amount_paid' => 5000, // ignoré : à crédit, rien n'est versé
            'customer_name' => 'Fatou',
            'customer_phone' => '70 11 22 33',
        ])->assertCreated()
            ->assertJsonPath('order.payment_status', 'unpaid')
            ->assertJsonPath('order.amount_paid', 0)
            ->assertJsonPath('order.payment_method', 'À crédit')
            ->assertJsonPath('order.customer_phone', '22670112233')
            ->assertJsonCount(0, 'order.payments');

        $this->assertSame(0, OrderPayment::count());
        $this->assertSame(4, $variant->fresh()->stock_quantity);
    }

    public function test_counter_sales_refuse_foreign_or_archived_articles_and_overpayment(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 5]])->variants->first();
        Sanctum::actingAs($this->owner);

        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'cash', 'amount_paid' => 20000])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'dépasse le total'));

        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'cash', 'customer_phone' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_phone');

        $variant->product->delete();
        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'introuvable dans votre catalogue'));

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertForbidden();
    }

    public function test_phone_numbers_of_any_country_are_kept_with_their_dial_code(): void
    {
        $this->assertSame('22670000001', PhoneNumber::normalize('70 00 00 01', 'BF'));
        $this->assertSame('22670000001', PhoneNumber::normalize('+226 70 00 00 01', 'BF'));
        $this->assertSame('2250708091011', PhoneNumber::normalize('00225 07 08 09 10 11', 'BF'));
        $this->assertSame('33612345678', PhoneNumber::normalize('+33 6 12 34 56 78', 'BF'));
        $this->assertSame('33612345678', PhoneNumber::normalize('06 12 34 56 78', 'FR'));
        $this->assertNull(PhoneNumber::normalize('abc', 'BF'));
        $this->assertNull(PhoneNumber::normalize('12', 'BF'));
    }

    // --- Commandes de l'assistant -----------------------------------------------------------------

    public function test_an_assistant_order_writes_its_lines_with_the_catalog_price(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();

        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 2, 'unit_price' => 1], ['name' => 'Ceinture', 'quantity' => 1, 'unit_price' => 2000]]);

        $this->assertSame(Order::SOURCE_AI, $order->source);
        $this->assertSame(
            [['Robe wax Awa', 'taille M', 2, 12500.0, 12500.0, 25000.0], ['Ceinture', null, 1, null, 2000.0, 2000.0]],
            $order->lines()->get()->map(fn (OrderItem $l) => [$l->name, $l->options, $l->quantity, $l->catalog_unit_price, $l->unit_price, $l->line_total])->all(),
        );
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $movement = StockMovement::where('reason', 'order')->sole();
        $this->assertSame([-2, 1, $order->id], [$movement->quantity, $movement->balance_after, $movement->order_id]);
    }

    public function test_status_changes_follow_the_allowed_transitions_and_set_their_dates(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();
        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]]);
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/orders/{$order->id}/status";

        $this->patchJson($url, ['status' => 'confirmed'])->assertOk()->assertJsonPath('order.confirmed_at', fn ($at) => $at !== null);

        // « Payée » solde la commande : le reste est un versement, au moyen choisi par le client.
        $this->patchJson($url, ['status' => 'paid'])->assertOk()
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.payments.0.method', 'orange_money')
            ->assertJsonPath('order.payments.0.amount', 12500);

        $this->patchJson($url, ['status' => 'delivered'])->assertOk()->assertJsonPath('order.delivered_at', fn ($at) => $at !== null);

        $this->patchJson($url, ['status' => 'new'])->assertUnprocessable()
            ->assertJsonPath('message', 'Une commande « livrée » ne peut pas passer à « nouvelle » : statuts possibles, annulée.');
        $this->patchJson($url, ['status' => 'delivered'])->assertOk();

        // Livrée puis annulée (retour) : les articles reviennent en stock.
        $this->patchJson($url, ['status' => 'cancelled', 'reason' => 'retour client'])->assertOk()
            ->assertJsonPath('order.cancellation_reason', 'retour client');
        $this->assertSame(3, $variant->fresh()->stock_quantity);

        // Réactivée : les articles ressortent, l'annulation est effacée.
        $this->patchJson($url, ['status' => 'confirmed'])->assertOk()->assertJsonPath('order.cancelled_at', null);
        $this->assertSame(2, $variant->fresh()->stock_quantity);
    }

    // --- Expiration des commandes non confirmées ---------------------------------------------------

    public function test_an_unconfirmed_assistant_order_expires_after_24_hours_and_returns_its_stock(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();
        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 2, 'unit_price' => 12500]]);
        $confirmed = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]], 'Awa');
        $confirmed->update(['status' => 'confirmed']);
        $this->assertSame(0, $variant->fresh()->stock_quantity);

        $this->travel(23)->hours();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('new', $order->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('orders:expire')->expectsOutputToContain('1 commande(s) non confirmée(s) annulée(s)')->assertSuccessful();

        $order->refresh();
        $this->assertSame(['cancelled', Order::REASON_NOT_CONFIRMED], [$order->status, $order->cancellation_reason]);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame(2, $variant->fresh()->stock_quantity);
        $this->assertSame([2, 2], [StockMovement::where('reason', 'order_expired')->sole()->quantity, StockMovement::where('reason', 'order_expired')->sole()->balance_after]);

        $notification = Notification::where('data->kind', 'order_expired')->sole();
        $this->assertSame(['order', $order->id], [$notification->type, $notification->data['order_id']]);
        $this->assertStringContainsString('annulée', $notification->title);

        // Idempotente : rien de plus au passage suivant.
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame(1, StockMovement::where('reason', 'order_expired')->count());
        $this->assertSame(1, Notification::where('data->kind', 'order_expired')->count());
    }

    public function test_an_order_with_a_payment_proof_never_expires_and_the_owner_is_warned_once(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();
        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 2, 'unit_price' => 12500]]);
        $messageId = (string) Str::uuid();
        DB::table('messages')->insert(['id' => $messageId, 'conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'content' => '[image]']);
        $order->update(['payment_proof_message_id' => $messageId]);

        $this->travel(30)->hours();
        $this->artisan('orders:expire')->expectsOutputToContain('1 gardée(s)')->assertSuccessful();
        $this->travel(5)->hours();
        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(1, $variant->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('reason', 'order_expired')->count());
        $notification = Notification::where('data->kind', 'order_unconfirmed_paid')->sole();
        $this->assertSame($order->id, $notification->data['order_id']);
        $this->assertStringContainsString('preuve de paiement', $notification->title);
    }

    public function test_the_expiry_delay_is_set_per_business_and_zero_disables_it(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();
        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]]);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/businesses/{$this->business->id}", ['order_expiry_hours' => 999])->assertUnprocessable()->assertJsonValidationErrors('order_expiry_hours');
        $this->putJson("/api/v1/businesses/{$this->business->id}", ['order_expiry_hours' => 48])->assertOk()->assertJsonPath('business.order_expiry_hours', 48);

        $this->travel(30)->hours();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('new', $order->fresh()->status);

        $this->business->update(['order_expiry_hours' => 0]);
        $this->travel(100)->hours();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('new', $order->fresh()->status);

        $this->business->update(['order_expiry_hours' => 48]);
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_counter_sales_never_expire(): void
    {
        $variant = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]])->variants->first();
        Sanctum::actingAs($this->owner);
        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'credit', 'customer_name' => 'Fatou', 'customer_phone' => '70112233'])->assertCreated();

        $this->travel(72)->hours();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('delivered', Order::sole()->status);
    }

    // --- Mouvements de stock et journal ------------------------------------------------------------

    public function test_the_owner_records_restock_loss_return_and_inventory(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 2], ['options' => ['taille' => 'L']]]);
        [$m, $l] = $robe->variants->all();
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'restock', 'quantity' => 10, 'note' => 'Arrivage Lomé', 'unit_cost' => 8000])
            ->assertCreated()->assertJsonPath('variant.stock_quantity', 12)->assertJsonPath('movement.balance_after', 12)->assertJsonPath('movement.unit_cost', 8000);
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'loss', 'quantity' => 20])
            ->assertUnprocessable()->assertJsonPath('message', "Impossible de retirer 20 pièce(s) de « Robe wax Awa taille M » : il n'en reste que 12 en stock.");
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'loss', 'quantity' => 1, 'note' => 'Tache'])->assertCreated()->assertJsonPath('variant.stock_quantity', 11);
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'return', 'quantity' => 1])->assertCreated()->assertJsonPath('variant.stock_quantity', 12);
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'adjustment', 'quantity' => 9, 'note' => 'Inventaire du samedi'])
            ->assertCreated()->assertJsonPath('variant.stock_quantity', 9)->assertJsonPath('movement.quantity', -3);
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'adjustment', 'quantity' => 9])->assertUnprocessable();
        $this->postJson("/api/v1/variants/{$m->id}/movements", ['reason' => 'manual', 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Variante non suivie : une perte est refusée, une réception active le suivi.
        $this->postJson("/api/v1/variants/{$l->id}/movements", ['reason' => 'loss', 'quantity' => 1])->assertUnprocessable();
        $this->postJson("/api/v1/variants/{$l->id}/movements", ['reason' => 'restock', 'quantity' => 4])->assertCreated()->assertJsonPath('variant.stock_quantity', 4);

        $journal = $this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements")->assertOk();
        $this->assertSame(['restock', 'adjustment', 'return', 'loss', 'restock', 'manual'], array_column($journal->json('data'), 'reason'));
        $journal->assertJsonPath('data.0.product_name', 'Robe wax Awa')->assertJsonPath('data.0.variant_label', 'taille L')->assertJsonPath('data.1.note', 'Inventaire du samedi')
            ->assertJsonPath('data.1.reason_label', 'Inventaire');

        $this->assertSame(['loss'], array_column($this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements?reason=loss")->json('data'), 'reason'));
        $other = $this->product('Pagne wax', 7500, [['options' => [], 'stock_quantity' => 1]]);
        $this->assertCount(1, $this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements?product_id={$other->id}")->json('data'));
        $this->assertCount(0, $this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements?from=2026-11-21")->json('data'));
        $this->assertCount(7, $this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements?from=2026-11-20&to=2026-11-20")->json('data'));
    }

    // --- Archivage ---------------------------------------------------------------------------------

    public function test_deleting_an_article_archives_it_and_keeps_its_history_and_sales(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3]]);
        $variant = $robe->variants->first();
        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]]);
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/products/{$robe->id}")->assertOk();

        $this->assertNull(Product::find($robe->id));
        $this->assertNotNull(Product::withTrashed()->find($robe->id)?->deleted_at);
        $this->assertSame(2, StockMovement::where('variant_id', $variant->id)->count());
        $this->assertSame($variant->id, OrderItem::sole()->variant_id);
        $this->getJson("/api/v1/businesses/{$this->business->id}/products")->assertJsonCount(0, 'data');
        $this->assertFalse(app(CatalogService::class)->isAvailable($this->business->fresh()));

        // La commande garde sa ligne, sans lien vers l'article archivé ; le journal le signale.
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('order.items.0.name', 'Robe wax Awa')->assertJsonMissingPath('order.items.0.product_id');
        $this->getJson("/api/v1/businesses/{$this->business->id}/stock-movements")->assertJsonPath('data.0.product_archived', true);

        // Annulée après l'archivage : la variante archivée reprend ses pièces.
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(3, ProductVariant::withTrashed()->find($variant->id)->stock_quantity);
    }

    public function test_removing_a_variant_archives_it_and_adding_it_back_restores_its_history(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 3], ['options' => ['taille' => 'L'], 'stock_quantity' => 1]]);
        [$m, $l] = $robe->variants->all();
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/products/{$robe->id}", ['variants' => [['id' => $m->id, 'options' => ['taille' => 'M']]]])->assertOk()->assertJsonCount(1, 'product.variants');
        $this->assertSoftDeleted('product_variants', ['id' => $l->id]);
        $this->assertSame(1, StockMovement::where('variant_id', $l->id)->count());

        // Même options : la variante archivée revient (même id, historique gardé).
        $this->putJson("/api/v1/products/{$robe->id}", ['variants' => [['id' => $m->id, 'options' => ['taille' => 'M']], ['options' => ['Taille' => ' l '], 'stock_quantity' => 1]]])
            ->assertOk()->assertJsonPath('product.variants.1.id', $l->id);
        $this->assertNull($l->fresh()->deleted_at);
        $this->assertSame(2, ProductVariant::withTrashed()->count());
    }

    public function test_options_are_unique_per_article_and_skus_per_business(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'sku' => 'RWA-M']]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/businesses/{$this->business->id}/products", [
            'name' => 'Chemise', 'base_price' => 9000, 'variants' => [['options' => ['taille' => 'M'], 'sku' => 'rwa-m']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku' => 'Le SKU « RWA-M » est déjà utilisé par l\'article « Robe wax Awa ».']);

        $this->postJson("/api/v1/businesses/{$this->business->id}/products", [
            'name' => 'Chemise', 'base_price' => 9000, 'variants' => [['options' => ['taille' => 'M'], 'sku' => 'CH-1'], ['options' => ['couleur' => 'bleu'], 'sku' => 'ch-1']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.1.sku' => 'Deux variantes ont le même SKU.']);

        // L'article garde son propre SKU en se modifiant.
        $this->putJson("/api/v1/products/{$robe->id}", ['variants' => [['id' => $robe->variants->first()->id, 'options' => ['taille' => 'M'], 'sku' => 'RWA-M']]])->assertOk();

        // La base refuse aussi les doublons (index partiels), hors variantes archivées.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $robe->variants()->create(['options' => ['TAILLE' => 'm']]);
    }

    public function test_an_archived_sku_can_be_reused(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'sku' => 'RWA-M']]);
        $robe->delete();
        $robe->variants()->withoutGlobalScopes()->get()->each->delete();

        $chemise = $this->product('Chemise', 9000, [['options' => ['taille' => 'M'], 'sku' => 'RWA-M']]);
        $this->assertSame('RWA-M', $chemise->variants->first()->sku);
    }

    public function test_outside_the_shop_profile_the_stock_is_ignored(): void
    {
        $plat = $this->product('Riz gras', 2500, [['options' => [], 'stock_quantity' => 0]]);
        $this->business->update(['activity_profile' => 'restaurant']);
        $variant = ProductVariant::with('product.business')->find($plat->variants->first()->id);

        $this->assertSame('available', $variant->availability);

        $order = $this->aiOrder([['variant_id' => $variant->id, 'name' => 'Riz gras', 'quantity' => 2, 'unit_price' => 2500]]);
        $this->assertSame('new', $order->status);
        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('reason', 'order')->count());
    }

    // --- Résumé des ventes --------------------------------------------------------------------------

    public function test_the_sales_summary_gives_revenue_collected_outstanding_and_top_articles(): void
    {
        $robe = $this->product('Robe wax Awa', 12500, [['options' => ['taille' => 'M'], 'stock_quantity' => 20], ['options' => ['taille' => 'L'], 'stock_quantity' => 20]]);
        [$m, $l] = $robe->variants->all();
        $pagne = $this->product('Pagne wax', 7500, [['options' => [], 'stock_quantity' => 20]])->variants->first();
        Sanctum::actingAs($this->owner);

        // Hier : une vente au comptoir payée.
        $this->travelTo('2026-11-19 15:00:00');
        $this->postJson($this->salesUrl(), ['items' => [['variant_id' => $pagne->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertCreated();

        // Aujourd'hui : une vente à prix négocié payée en partie, une commande confirmée, une nouvelle et une annulée.
        $this->travelTo('2026-11-20 11:00:00');
        $this->postJson($this->salesUrl(), [
            'items' => [['variant_id' => $m->id, 'quantity' => 2, 'unit_price' => 12000], ['variant_id' => $l->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 20000, 'customer_name' => 'Fatou', 'customer_phone' => '70112233',
        ])->assertCreated();
        $this->aiOrder([['variant_id' => $pagne->id, 'name' => 'Pagne', 'quantity' => 3, 'unit_price' => 7500]])->update(['status' => 'confirmed']);
        $this->aiOrder([['variant_id' => $m->id, 'name' => 'Robe', 'quantity' => 1, 'unit_price' => 12500]], 'Awa');
        $cancelled = $this->aiOrder([['variant_id' => $l->id, 'name' => 'Robe', 'quantity' => 5, 'unit_price' => 12500]], 'Bintou');
        $cancelled->update(['status' => 'cancelled']);

        $summary = $this->getJson("/api/v1/businesses/{$this->business->id}/sales/summary")->assertOk();

        // 2 × 12 000 + 12 500 = 36 500 au comptoir ; 3 × 7 500 = 22 500 confirmée.
        $summary->assertJsonPath('current.revenue', 59000)
            ->assertJsonPath('current.collected', 20000)
            ->assertJsonPath('current.sales_count', 2)
            ->assertJsonPath('current.counter_sales_count', 1)
            ->assertJsonPath('current.items_sold', 6)
            ->assertJsonPath('previous.revenue', 7500)
            ->assertJsonPath('outstanding.amount', 39000)
            ->assertJsonPath('outstanding.orders_count', 2)
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('series.6.revenue', 59000)
            ->assertJsonPath('top_products.0.name', 'Robe wax Awa')
            ->assertJsonPath('top_products.0.quantity', 3)
            ->assertJsonPath('top_products.0.revenue', 36500)
            ->assertJsonPath('top_products.1.name', 'Pagne wax');

        $this->getJson("/api/v1/businesses/{$this->business->id}/sales/summary?period=month")->assertOk()
            ->assertJsonCount(6, 'series')
            ->assertJsonPath('current.revenue', 66500)
            ->assertJsonPath('current.collected', 27500);
        $this->getJson("/api/v1/businesses/{$this->business->id}/sales/summary?period=year")->assertUnprocessable();
    }

    // --- Outils ---------------------------------------------------------------------------------------

    private function salesUrl(): string
    {
        return "/api/v1/businesses/{$this->business->id}/sales";
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function product(string $name, float $price, array $variants): Product
    {
        return app(CatalogService::class)->saveProduct($this->business->fresh(), ['name' => $name, 'base_price' => $price], null, $variants);
    }

    /**
     * Commande de l'assistant (outil create_order), dans une conversation propre au client.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function aiOrder(array $items, ?string $customer = null): Order
    {
        $conversation = $this->conversation;
        if ($customer !== null) {
            $id = (string) Str::uuid();
            DB::table('conversations')->insert(['id' => $id, 'business_id' => $this->business->id, 'customer_phone' => '2267'.random_int(1000000, 9999999), 'customer_name' => $customer]);
            $conversation = Conversation::findOrFail($id);
        }

        return app(OrderService::class)->createFromAi($this->business->fresh(), $conversation, [
            'items' => $items, 'fulfillment_type' => 'pickup', 'customer_name' => $conversation->customer_name, 'payment_method' => 'Orange Money',
        ]);
    }
}
