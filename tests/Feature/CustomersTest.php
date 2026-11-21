<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogService;
use App\Services\Customers\CustomerService;
use App\Services\OrderService;
use App\Services\Sales\OrderStatusService;
use App\Services\Sales\PaymentService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Clients : une liste par entreprise (un numéro = un client), alimentée par les messages WhatsApp
 * et les ventes au comptoir ; totaux, reste à payer, recherche, tri, filtre, fiche, nom et note
 * du commerçant, export vCard.
 */
class CustomersTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-21 10:00:00');
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_30_100000_add_contacts_exported_at_to_businesses.php'))->up();
        Mail::fake();

        $this->owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'name' => 'Awa Couture', 'type' => 'boutique',
            'modules' => '["orders","catalog"]', 'delivery_enabled' => true, 'pickup_enabled' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);
        $this->robe = app(CatalogService::class)->saveProduct($this->business, ['name' => 'Robe wax', 'base_price' => 10000], null, [
            ['options' => ['taille' => 'M'], 'stock_quantity' => 100],
        ]);
        Sanctum::actingAs($this->owner);
    }

    // --- Numéros ----------------------------------------------------------------------------------

    public function test_the_same_number_written_several_ways_gives_one_key_in_any_country(): void
    {
        foreach (['+226 70 12 34 56', '00226 70 12 34 56', '70 12 34 56', '70.12.34.56', '22670123456'] as $spelling) {
            $this->assertSame('22670123456', PhoneNumber::normalize($spelling, 'BF'), $spelling);
        }
        // Pays absent de l'ancienne table d'indicatifs.
        $this->assertSame('254712345678', PhoneNumber::normalize('0712 345 678', 'KE'));
        $this->assertSame('5511987654321', PhoneNumber::normalize('+55 11 98765-4321', 'BF'));
        // Numéro ivoirien tapé sans « + » par une boutique du Burkina.
        $this->assertSame('2250701020304', PhoneNumber::normalize('2250701020304', 'BF'));
        $this->assertNull(PhoneNumber::normalize('abc', 'BF'));
        $this->assertNull(PhoneNumber::normalize('12', 'BF'));

        // wa_id : gardé tel quel ; numéro local : indicatif de l'entreprise.
        $this->assertSame('2250701020304', PhoneNumber::canonical('2250701020304', 'BF'));
        $this->assertSame('22670123456', PhoneNumber::canonical('70123456', 'BF'));
        $this->assertSame('', PhoneNumber::canonical(null, 'BF'));
    }

    // --- Mise à jour automatique ------------------------------------------------------------------

    public function test_an_incoming_whatsapp_message_creates_the_customer_once_and_follows_the_profile_name(): void
    {
        $conversation = $this->whatsappMessage('22670123456', 'Mariam');
        $this->whatsappMessage('22670123456', 'Mariam');

        $customer = Customer::sole();
        $this->assertSame(['22670123456', 'Mariam', null], [$customer->phone, $customer->whatsapp_name, $customer->name]);
        $this->assertSame($customer->id, $conversation->fresh()->customer_id);
        $this->assertSame('2026-11-21 10:00:00', $customer->first_seen_at->format('Y-m-d H:i:s'));

        // Nouveau nom de profil : suivi, sans toucher au nom choisi par le commerçant.
        $customer->update(['name' => 'Mariam (cliente fidèle)']);
        $this->whatsappMessage('22670123456', 'Mariam K.');
        $this->assertSame(['Mariam K.', 'Mariam (cliente fidèle)'], [$customer->fresh()->whatsapp_name, $customer->fresh()->name]);

        // Le pont WhatsApp met le numéro à défaut de nom : ignoré.
        $this->whatsappMessage('22676000000', '22676000000');
        $this->assertNull(Customer::where('phone', '22676000000')->sole()->whatsapp_name);
    }

    public function test_resolving_the_same_number_twice_never_creates_a_duplicate(): void
    {
        $service = app(CustomerService::class);
        $first = $service->resolve($this->business->id, 'BF', '70123456');
        $second = $service->resolve($this->business->id, 'BF', '+226 70 12 34 56');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Customer::count());
        $this->assertNull($service->resolve($this->business->id, 'BF', ''));
    }

    public function test_a_counter_sale_joins_the_whatsapp_customer_and_fills_the_name_only_if_empty(): void
    {
        $this->whatsappMessage('22670123456', 'Mariam');

        // Autre écriture du même numéro : même client. Le nom saisi remplit le nom du commerçant (vide).
        $this->counterSale(['customer_phone' => '70 12 34 56', 'customer_name' => 'Mariam Kaboré']);
        $customer = Customer::sole();
        $this->assertSame('Mariam Kaboré', $customer->name);
        $this->assertSame($customer->id, Order::sole()->customer_id);

        // Un nom existant n'est jamais écrasé.
        $this->counterSale(['customer_phone' => '+226 70 12 34 56', 'customer_name' => 'Autre nom']);
        $this->assertSame('Mariam Kaboré', $customer->fresh()->name);
        $this->assertSame(1, Customer::count());

        // Vente sans numéro : anonyme, aucun client.
        $this->counterSale(['customer_name' => 'Passant']);
        $this->assertSame(1, Customer::count());
        $this->assertNull(Order::latest('id')->where('customer_name', 'Passant')->sole()->customer_id);
    }

    public function test_an_assistant_order_belongs_to_the_customer_of_its_conversation(): void
    {
        $conversation = $this->whatsappMessage('22670123456', null);
        $order = app(OrderService::class)->createFromAi($this->business->fresh(), $conversation, [
            'items' => [['name' => 'Robe wax', 'quantity' => 1, 'unit_price' => 10000]],
            'fulfillment_type' => 'pickup', 'customer_name' => 'Fatim', 'payment_method' => 'Orange Money',
        ]);

        $customer = Customer::sole();
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        // Sans nom de profil : le nom donné à l'assistant sert de nom WhatsApp, pas de nom du commerçant.
        $this->assertSame(['Fatim', null], [$customer->whatsapp_name, $customer->name]);
    }

    // --- Liste -----------------------------------------------------------------------------------

    public function test_totals_exclude_cancelled_and_pending_orders_and_the_balance_counts_credit_and_partial_payments(): void
    {
        $conversation = $this->whatsappMessage('22670123456', 'Mariam');
        $this->counterSale(['customer_phone' => '70123456', 'customer_name' => 'Mariam', 'payment_method' => 'credit'], 2); // 20 000 à crédit
        $this->counterSale(['customer_phone' => '70123456', 'customer_name' => 'Mariam', 'payment_method' => 'cash', 'amount_paid' => 4000]); // 10 000, 4 000 versés
        $this->counterSale(['customer_phone' => '70123456', 'payment_method' => 'cash']); // 10 000 payés
        // Annulée après un versement : ni dépensée, ni due ; encaissé signalé sur la fiche.
        $cancelled = $this->counterSale(['customer_phone' => '70123456', 'customer_name' => 'Mariam', 'payment_method' => 'cash', 'amount_paid' => 3000]);
        app(OrderStatusService::class)->change($cancelled, 'cancelled', $this->owner->id, 'retour');
        // Commande « new » de l'assistant : en attente, à part.
        app(OrderService::class)->createFromAi($this->business->fresh(), $conversation, [
            'items' => [['name' => 'Robe wax', 'quantity' => 1, 'unit_price' => 10000]],
            'fulfillment_type' => 'pickup', 'customer_name' => 'Mariam', 'payment_method' => 'Orange Money',
        ]);

        $row = $this->list()->assertOk()->json('data.0');

        $this->assertSame('both', $row['origin']);
        $this->assertSame(3, $row['orders_count']);
        $this->assertSame(1, $row['pending_orders_count']);
        $this->assertEquals(40000, $row['total_spent']);
        $this->assertEquals(26000, $row['balance_due']);
        $this->assertNotNull($row['last_message_at']);

        $customer = $this->getJson("/api/v1/customers/{$row['id']}")->assertOk();
        $customer->assertJsonPath('customer.cancelled_paid', 3000)
            ->assertJsonPath('customer.orders_total_count', 5)
            ->assertJsonPath('can_add_payment', true)
            ->assertJsonCount(5, 'orders')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $conversation->id);
        $this->assertEquals(0, collect($customer->json('orders'))->firstWhere('id', $cancelled->id)['balance_due']);
    }

    public function test_search_sort_owes_filter_and_pagination(): void
    {
        $this->whatsappMessage('22670000001', 'Awa Ouédraogo');
        $this->travel(1)->hour();
        $this->counterSale(['customer_phone' => '+33 6 12 34 56 78', 'customer_name' => 'Zoé', 'payment_method' => 'credit'], 3);
        $this->travel(1)->hour();
        $this->counterSale(['customer_phone' => '76 00 00 00', 'customer_name' => 'Issa', 'payment_method' => 'cash', 'amount_paid' => 5000]);
        $this->travel(1)->hour();
        $this->whatsappMessage('22665000000', null);

        // Par défaut : activité la plus récente d'abord.
        $this->assertSame(['Client 0000', 'Issa', 'Zoé', 'Awa Ouédraogo'], array_column($this->list()->json('data'), 'display_name'));
        $this->assertSame(['Awa Ouédraogo', 'Issa', 'Zoé', 'Client 0000'], array_column($this->list('sort=name')->json('data'), 'display_name'));
        $this->assertSame(['Zoé', 'Issa'], array_slice(array_column($this->list('sort=spent')->json('data'), 'display_name'), 0, 2));
        $this->assertSame(['Zoé', 'Issa'], array_slice(array_column($this->list('sort=due')->json('data'), 'display_name'), 0, 2));
        // Sans message (comptoir seulement) : à la fin, dans un ordre stable mais quelconque.
        $byMessage = array_column($this->list('sort=last_message')->json('data'), 'display_name');
        $this->assertSame(['Client 0000', 'Awa Ouédraogo'], array_slice($byMessage, 0, 2));
        $this->assertEqualsCanonicalizing(['Issa', 'Zoé'], array_slice($byMessage, 2));

        // Recherche : nom (sans casse), numéro avec espaces, numéro local d'un autre pays.
        $this->assertSame(['Awa Ouédraogo'], array_column($this->list('search=awa')->json('data'), 'display_name'));
        $this->assertSame(['Issa'], array_column($this->list('search='.urlencode('76 00 00'))->json('data'), 'display_name'));
        $this->assertSame(['Zoé'], array_column($this->list('search='.urlencode('+33 6 12 34 56 78'))->json('data'), 'display_name'));

        $owes = $this->list('owes=1')->assertOk();
        $this->assertSame(['Issa', 'Zoé'], array_column($owes->json('data'), 'display_name'));
        $owes->assertJsonPath('totals.customers_count', 4)
            ->assertJsonPath('totals.owing_count', 2)
            ->assertJsonPath('totals.owing_total', 35000);

        foreach (range(1, 21) as $i) {
            app(CustomerService::class)->resolve($this->business->id, 'BF', '2266'.str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }
        $this->list()->assertJsonPath('total', 25)->assertJsonPath('per_page', 20)->assertJsonCount(20, 'data');
        $this->list('page=2')->assertJsonCount(5, 'data');

        $this->list('sort=phone')->assertUnprocessable();
    }

    // --- Fiche -----------------------------------------------------------------------------------

    public function test_the_merchant_edits_the_name_and_the_note_and_adds_a_payment_from_the_card(): void
    {
        $this->whatsappMessage('22670123456', 'Mariam');
        $sale = $this->counterSale(['customer_phone' => '70123456', 'customer_name' => 'Mariam', 'payment_method' => 'credit']);
        $customer = Customer::sole();

        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => '  Mariam   la couturière ', 'note' => 'Cliente fidèle, préfère le rouge'])
            ->assertOk()
            ->assertJsonPath('customer.display_name', 'Mariam la couturière')
            ->assertJsonPath('customer.note', 'Cliente fidèle, préfère le rouge');

        // Versement depuis la fiche : la route des commandes (PaymentService).
        $this->postJson("/api/v1/orders/{$sale->id}/payments", ['amount' => 4000, 'method' => 'orange_money'])->assertCreated();
        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertJsonPath('customer.balance_due', 6000)
            ->assertJsonPath('orders.0.payments.0.amount', 4000);

        // Nom vidé : retour au nom WhatsApp.
        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => ''])->assertOk()->assertJsonPath('customer.display_name', 'Mariam');
        $this->patchJson("/api/v1/customers/{$customer->id}", ['note' => str_repeat('a', 2001)])->assertUnprocessable();
    }

    public function test_only_the_owner_sees_and_edits_customers(): void
    {
        $this->whatsappMessage('22670123456', 'Mariam');
        $customer = Customer::sole();

        foreach ([
            User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']),
            User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]),
        ] as $user) {
            Sanctum::actingAs($user);
            $this->list()->assertForbidden();
            $this->getJson("/api/v1/customers/{$customer->id}")->assertForbidden();
            $this->patchJson("/api/v1/customers/{$customer->id}", ['note' => 'x'])->assertForbidden();
        }
        $this->assertNull($customer->fresh()->note);
    }

    public function test_customers_are_listed_in_the_free_plan_without_the_payment_button(): void
    {
        $this->assignPlan($this->business->id, Plan::FREE);
        DB::table('businesses')->where('id', $this->business->id)->update(['modules' => '[]']);
        $this->whatsappMessage('22670123456', 'Mariam');

        $id = $this->list()->assertOk()->json('data.0.id');
        $this->getJson("/api/v1/customers/{$id}")->assertOk()->assertJsonPath('can_add_payment', false);
    }

    // --- Export ----------------------------------------------------------------------------------

    public function test_the_vcard_uses_the_merchant_name_and_includes_counter_only_customers(): void
    {
        $this->travelTo('2026-11-02 09:00:00');
        $this->whatsappMessage('22670123456', 'Mariam');
        $this->travelTo('2026-11-05 09:00:00');
        $this->counterSale(['customer_phone' => '76 00 00 00', 'customer_name' => 'Issa', 'payment_method' => 'cash']);
        Customer::where('phone', '22670123456')->update(['name' => 'Mariam la couturière']);

        $body = $this->get("/api/v1/businesses/{$this->business->id}/contacts/export?scope=all")->assertOk()->getContent();

        $this->assertSame(2, substr_count($body, 'BEGIN:VCARD'));
        $this->assertStringContainsString("FN:Mariam la couturière · Awa Couture\r\n", $body);
        $this->assertStringContainsString("TEL;TYPE=CELL:+22670123456\r\nNOTE:Client WhatsApp depuis le 02/11/2026\r\n", $body);
        $this->assertStringContainsString("FN:Issa · Awa Couture\r\n", $body);
        $this->assertStringContainsString("TEL;TYPE=CELL:+22676000000\r\nNOTE:Client depuis le 05/11/2026\r\n", $body);
        $this->getJson("/api/v1/businesses/{$this->business->id}/contacts/summary")->assertJson(['total' => 2, 'new_since_last_export' => 2]);
    }

    public function test_customer_names_and_numbers_are_never_logged_when_linking_fails(): void
    {
        Log::spy();
        // Table absente : le message est enregistré quand même, l'erreur est journalisée sans donnée client.
        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::drop('customers');

        $conversation = $this->whatsappMessage('22670123456', 'Mariam');

        $this->assertNull($conversation->fresh()->customer_id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Clients : rattachement impossible'
            && ! str_contains(json_encode($context), '70123456') && ! str_contains(json_encode($context), 'Mariam'));
    }

    // --- Outils ----------------------------------------------------------------------------------

    /** Même enchaînement que le webhook à la réception d'un message (WebhookController::process). */
    private function whatsappMessage(string $phone, ?string $name): Conversation
    {
        $conversation = Conversation::firstOrCreate(['business_id' => $this->business->id, 'customer_phone' => $phone], ['customer_name' => $name]);
        $conversation->update(['customer_name' => $name ?? $conversation->customer_name, 'last_message_at' => now(), 'is_active' => true]);
        $conversation->messages()->create(['direction' => 'inbound', 'sender_type' => 'customer', 'content' => 'Bonjour', 'status' => 'pending']);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function counterSale(array $data, int $quantity = 1): Order
    {
        $response = $this->postJson("/api/v1/businesses/{$this->business->id}/sales", $data + [
            'items' => [['variant_id' => $this->robe->variants->first()->id, 'quantity' => $quantity]],
            'payment_method' => 'cash',
        ])->assertCreated();

        return Order::findOrFail($response->json('order.id'));
    }

    private function list(string $query = '')
    {
        return $this->getJson("/api/v1/businesses/{$this->business->id}/customers".($query !== '' ? "?{$query}" : ''));
    }
}
