<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Embedding\EmbeddingService;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    private const CONTEXT = 'Pagne wax : 7 500 FCFA, coloris bleu ou rouge. Livraison à Ouagadougou : 1 000 FCFA. '
        .'Paiement par Orange Money ou à la livraison.';

    private User $owner;

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);

        // Les migrations complètes dépendent de pgvector : on crée ici un schéma
        // minimal sur SQLite, puis les vraies migrations notifications et orders.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->timestamps();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('direction');
            $table->string('sender_type')->nullable();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_07_100000_create_notifications_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_110000_create_orders_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_120000_add_fulfillment_options.php'))->up();
        Schema::create('business_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
            $table->string('type');
        });
        (require base_path('database/migrations/2026_10_08_100000_add_modules_to_businesses.php'))->up();

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);

        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $businessId, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '["orders"]']);
        $this->business = Business::findOrFail($businessId);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $this->conversationId,
            'business_id' => $businessId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
        ]);
    }

    public function test_confirmed_order_is_created_through_the_tool_and_confirmed_to_the_customer(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUseResponse([
                'items' => [
                    ['name' => 'Pagne wax', 'options' => 'bleu', 'quantity' => 2, 'unit_price' => 7500],
                    ['name' => 'Frais de livraison', 'quantity' => 1, 'unit_price' => 1000],
                ],
                'customer_name' => 'Awa Ouédraogo',
                'delivery_city' => 'Ouagadougou',
                'delivery_address' => 'Secteur 15',
                'fulfillment_type' => 'delivery',
                'payment_method' => 'Orange Money',
                'total_amount' => 1, // ignoré : le serveur recalcule
            ]))
            ->push($this->textResponse('Votre commande est enregistrée ✅ Total : 16 000 FCFA.')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Votre commande est enregistrée ✅ Total : 16 000 FCFA.', $result['answer']);
        $this->assertFalse($result['should_escalate']);

        $order = Order::sole();
        $this->assertSame(16000.0, $order->total_amount);
        $this->assertSame('new', $order->status);
        $this->assertSame($this->conversationId, $order->conversation_id);
        $this->assertSame('22670000001', $order->customer_phone);
        $this->assertSame('Awa Ouédraogo', $order->customer_name);
        $this->assertSame('Ouagadougou', $order->delivery_city);
        $this->assertSame('Secteur 15', $order->delivery_address);
        $this->assertSame('Orange Money', $order->payment_method);
        $this->assertSame('delivery', $order->fulfillment_type);
        $this->assertNull($order->pickup_time);
        $this->assertEquals([
            ['name' => 'Pagne wax', 'options' => 'bleu', 'quantity' => 2, 'unit_price' => 7500],
            ['name' => 'Frais de livraison', 'options' => null, 'quantity' => 1, 'unit_price' => 1000],
        ], $order->items);

        $notification = Notification::sole();
        $this->assertSame('order', $notification->type);
        $this->assertSame('Nouvelle commande de Awa Ouédraogo', $notification->title);
        $this->assertSame('2 × Pagne wax (bleu), 1 × Frais de livraison — total 16 000 FCFA, livraison Ouagadougou', $notification->body);
        $this->assertSame(['conversation_id' => $this->conversationId, 'order_id' => $order->id], $notification->data);

        Http::assertSentCount(2);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data());

        // 1er appel : « oui » est bien envoyé à Claude avec l'outil et les règles de commande.
        $this->assertSame('create_order', $requests[0]['tools'][0]['name']);
        $this->assertSame(['delivery', 'pickup'], $requests[0]['tools'][0]['input_schema']['properties']['fulfillment_type']['enum']);
        $this->assertStringContainsString('- livraison à domicile (delivery)', $requests[0]['system']);
        $this->assertStringContainsString('- retrait en boutique (pickup)', $requests[0]['system']);
        $this->assertStringNotContainsString('(shipping)', $requests[0]['system']);
        $this->assertStringContainsString('# Prise de commande', $requests[0]['system']);
        $this->assertStringContainsString('Je confirme la commande ?', $requests[0]['messages'][1]['content']);
        $this->assertStringEndsWith('Question du client : oui', $requests[0]['messages'][2]['content']);

        // 2e appel : le tool_result porte la référence et le total recalculé.
        $toolResult = $requests[1]['messages'][4]['content'][0];
        $this->assertSame('tool_result', $toolResult['type']);
        $this->assertSame('toolu_1', $toolResult['tool_use_id']);
        $this->assertArrayNotHasKey('is_error', $toolResult);
        $payload = json_decode($toolResult['content'], true);
        $this->assertSame($order->reference(), $payload['reference']);
        $this->assertEquals(16000, $payload['total_amount']);
    }

    public function test_tool_error_is_returned_to_claude_and_no_order_is_created(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUseResponse([
                'items' => [['name' => 'Pagne wax', 'quantity' => 2]],
                'customer_name' => 'Awa',
                'delivery_city' => 'Ouagadougou',
                'delivery_address' => 'Secteur 15',
                'fulfillment_type' => 'delivery',
                'payment_method' => 'Orange Money',
            ]))
            ->push($this->textResponse('Je vérifie ça et je reviens vers vous très vite 😊')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Je vérifie ça et je reviens vers vous très vite 😊', $result['answer']);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Notification::count());

        $toolResult = Http::recorded()[1][0]->data()['messages'][4]['content'][0];
        $this->assertTrue($toolResult['is_error']);
        $this->assertStringContainsString('prix unitaire', $toolResult['content']);
    }

    public function test_missing_customer_information_is_rejected(): void
    {
        $conversation = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversation->update(['customer_name' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('customer_name, delivery_address');

        app(OrderService::class)->createFromAi($this->business, $conversation, [
            'items' => [['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 7500]],
            'delivery_city' => 'Ouagadougou',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'Orange Money',
        ]);
    }

    public function test_identical_confirmation_does_not_duplicate_the_order(): void
    {
        $conversation = \App\Models\Conversation::findOrFail($this->conversationId);
        $input = [
            'items' => [['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 7500]],
            'customer_name' => 'Awa',
            'delivery_city' => 'Ouagadougou',
            'delivery_address' => 'Secteur 15',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'À la livraison',
        ];

        $first = app(OrderService::class)->createFromAi($this->business, $conversation, $input);
        $second = app(OrderService::class)->createFromAi($this->business, $conversation, $input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Notification::count());
    }

    public function test_plain_yes_without_previous_bot_reply_is_still_answered_locally(): void
    {
        Http::fake();

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertNotNull($result['answer']);
        Http::assertNothingSent();
    }

    public function test_order_tool_is_not_offered_without_a_conversation(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Le pagne wax coûte 7 500 FCFA.'))]);

        $this->service()->answer($this->business, 'Combien coûte le pagne wax ?');

        Http::assertSent(fn (Request $request) => ! array_key_exists('tools', $request->data())
            && ! str_contains($request->data()['system'], '# Prise de commande'));
    }

    public function test_index_filters_by_status_and_lists_latest_first(): void
    {
        $older = $this->makeOrder('new');
        $this->travel(1)->minutes();
        $this->makeOrder('delivered');
        $this->travel(1)->minutes();
        $latest = $this->makeOrder('new');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('data.0.id', $latest->id);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders?status=new")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.1.id', $older->id);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders?status=unknown")->assertUnprocessable();
    }

    public function test_show_and_update_status(): void
    {
        $order = $this->makeOrder('new');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.items.0.name', 'Pagne wax');

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('order.status', 'confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])->assertUnprocessable();
        $this->patchJson("/api/v1/orders/{$order->id}/status", [])->assertUnprocessable();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_orders_endpoints_are_forbidden_without_the_orders_module(): void
    {
        $order = $this->makeOrder('new');
        $this->business->update(['modules' => []]);

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")
            ->assertForbidden()
            ->assertJsonPath('message', "Le module Commandes n'est pas activé pour cette entreprise.");
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();

        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_order_tool_and_prompt_are_not_offered_without_the_orders_module(): void
    {
        $this->seedRecapConversation();
        $this->business->update(['modules' => []]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Je vérifie ça et je reviens vers vous très vite 😊'))]);

        $this->service()->answer($this->business, 'oui', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertArrayNotHasKey('tools', $request);
        $this->assertStringNotContainsString('# Prise de commande', $request['system']);
        $this->assertSame(0, Order::count());
    }

    public function test_other_users_cannot_access_orders(): void
    {
        $order = $this->makeOrder('new');

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();

        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_pickup_order_needs_no_address_and_stores_pickup_time(): void
    {
        $order = app(OrderService::class)->createFromAi($this->business, $this->conversation(), [
            'items' => [['name' => 'Pagne wax', 'quantity' => 2, 'unit_price' => 7500]],
            'fulfillment_type' => 'pickup',
            'pickup_time' => 'samedi matin',
            'delivery_city' => 'Ouagadougou', // ignoré pour un retrait
            'customer_name' => 'Awa',
            'payment_method' => 'À la boutique',
        ]);

        $this->assertSame('pickup', $order->fulfillment_type);
        $this->assertSame('samedi matin', $order->pickup_time);
        $this->assertNull($order->delivery_city);
        $this->assertNull($order->delivery_address);
        $this->assertSame(15000.0, $order->total_amount);
        $this->assertSame('2 × Pagne wax — total 15 000 FCFA, retrait en boutique (samedi matin)', Notification::sole()->body);
    }

    public function test_pickup_order_refuses_delivery_fees(): void
    {
        foreach (['Frais de livraison', 'Livraison Ouaga', 'Frais de port'] as $feeLine) {
            try {
                app(OrderService::class)->createFromAi($this->business, $this->conversation(), [
                    'items' => [
                        ['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 7500],
                        ['name' => $feeLine, 'quantity' => 1, 'unit_price' => 1000],
                    ],
                    'fulfillment_type' => 'pickup',
                    'customer_name' => 'Awa',
                    'payment_method' => 'À la boutique',
                ]);
                $this->fail("La ligne « {$feeLine} » aurait dû être refusée.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Aucun frais de livraison', $e->getMessage());
            }
        }

        $this->assertSame(0, Order::count());
    }

    public function test_delivery_requires_city_and_address_and_shipping_requires_city(): void
    {
        $this->business->update(['shipping_enabled' => true]);
        $base = [
            'items' => [['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 7500]],
            'customer_name' => 'Awa',
            'payment_method' => 'Orange Money',
        ];

        $this->assertOrderRejected($base + ['fulfillment_type' => 'delivery', 'delivery_city' => 'Ouagadougou'], 'delivery_address');
        $this->assertOrderRejected($base + ['fulfillment_type' => 'delivery', 'delivery_address' => 'Secteur 15'], 'delivery_city');
        $this->assertOrderRejected($base + ['fulfillment_type' => 'shipping'], 'delivery_city');

        $shipped = app(OrderService::class)->createFromAi($this->business, $this->conversation(), $base + [
            'fulfillment_type' => 'shipping',
            'delivery_city' => 'Bobo-Dioulasso',
        ]);

        $this->assertSame('shipping', $shipped->fulfillment_type);
        $this->assertSame('Bobo-Dioulasso', $shipped->delivery_city);
        $this->assertNull($shipped->delivery_address);
        $this->assertStringEndsWith('expédition vers Bobo-Dioulasso', Notification::sole()->body);
    }

    public function test_disabled_or_missing_fulfillment_type_is_refused(): void
    {
        $base = [
            'items' => [['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 7500]],
            'customer_name' => 'Awa',
            'delivery_city' => 'Bobo-Dioulasso',
            'payment_method' => 'Orange Money',
        ];

        // Expédition désactivée par défaut.
        $this->assertOrderRejected($base + ['fulfillment_type' => 'shipping'], "n'est pas proposé par la boutique. Modes disponibles : delivery, pickup.");
        $this->assertOrderRejected($base + ['fulfillment_type' => 'teleportation'], 'Mode de remise inconnu');
        $this->assertOrderRejected($base, 'Mode de remise manquant');

        $this->business->update(['pickup_enabled' => false]);
        $this->assertOrderRejected($base + ['fulfillment_type' => 'pickup'], 'Modes disponibles : delivery.');

        $this->assertSame(0, Order::count());
    }

    public function test_prompt_and_tool_only_offer_enabled_modes(): void
    {
        $this->seedRecapConversation();
        $this->business->update(['delivery_enabled' => false, 'pickup_enabled' => true, 'shipping_enabled' => true]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Comment souhaitez-vous recevoir votre commande ?'))]);

        $this->service()->answer($this->business, 'Je veux un pagne wax', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertSame(['pickup', 'shipping'], $request['tools'][0]['input_schema']['properties']['fulfillment_type']['enum']);
        $this->assertSame(['items', 'fulfillment_type', 'customer_name', 'payment_method'], $request['tools'][0]['input_schema']['required']);
        $this->assertStringContainsString('- retrait en boutique (pickup)', $request['system']);
        $this->assertStringContainsString('- expédition vers une autre ville (shipping)', $request['system']);
        $this->assertStringNotContainsString('(delivery)', $request['system']);
        $this->assertStringContainsString('Ne facture JAMAIS de frais de livraison pour un retrait en boutique', $request['system']);
    }

    public function test_order_tool_is_not_offered_when_every_mode_is_disabled(): void
    {
        $this->seedRecapConversation();
        $this->business->update(['delivery_enabled' => false, 'pickup_enabled' => false, 'shipping_enabled' => false]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Je vérifie ça et je reviens vers vous très vite 😊'))]);

        $this->service()->answer($this->business, 'Je veux un pagne wax', $this->conversationId);

        $request = Http::recorded()[0][0]->data();
        $this->assertArrayNotHasKey('tools', $request);
        $this->assertStringNotContainsString('# Prise de commande', $request['system']);
    }

    public function test_owner_can_toggle_fulfillment_modes(): void
    {
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['shipping_enabled' => true, 'pickup_enabled' => false])
            ->assertOk()
            ->assertJsonPath('business.shipping_enabled', true)
            ->assertJsonPath('business.pickup_enabled', false);

        $fresh = $this->business->fresh();
        $this->assertTrue($fresh->delivery_enabled);
        $this->assertFalse($fresh->pickup_enabled);
        $this->assertTrue($fresh->shipping_enabled);
        $this->assertSame(['delivery', 'shipping'], $fresh->enabledFulfillmentTypes());

        $this->patchJson("/api/v1/businesses/{$this->business->id}", ['delivery_enabled' => 'peut-être'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery_enabled');
    }

    public function test_search_matches_reference_customer_phone_city_and_item_names(): void
    {
        $awa = $this->makeOrder('new', ['customer_name' => 'Awa Ouédraogo']);
        $issa = $this->makeOrder('new', [
            'customer_name' => 'Issa Kaboré',
            'customer_phone' => '22676543210',
            'delivery_city' => 'Bobo-Dioulasso',
            'items' => [['name' => 'Sac en cuir', 'options' => 'marron', 'quantity' => 1, 'unit_price' => 12000]],
        ]);

        Sanctum::actingAs($this->owner);

        $this->assertSearch(['q' => strtolower($issa->reference())], [$issa]);
        $this->assertSearch(['q' => 'AWA'], [$awa]);
        $this->assertSearch(['q' => '6543'], [$issa]);
        $this->assertSearch(['q' => 'bobo'], [$issa]);
        $this->assertSearch(['q' => 'PAGNE'], [$awa]);
        $this->assertSearch(['q' => 'cuir'], [$issa]);
        $this->assertSearch(['q' => 'marron'], []); // les options ne sont pas cherchées
        $this->assertSearch(['q' => '%'], []);
        $this->assertSearch(['q' => 'introuvable'], []);
    }

    public function test_search_combines_with_status_and_fulfillment_type(): void
    {
        $deliveredPickup = $this->makeOrder('delivered', ['fulfillment_type' => 'pickup', 'delivery_city' => null, 'delivery_address' => null]);
        $newPickup = $this->makeOrder('new', ['fulfillment_type' => 'pickup', 'delivery_city' => null, 'delivery_address' => null]);
        $newDelivery = $this->makeOrder('new');

        Sanctum::actingAs($this->owner);

        $this->assertSearch(['fulfillment_type' => 'pickup'], [$deliveredPickup, $newPickup]);
        $this->assertSearch(['fulfillment_type' => 'pickup', 'status' => 'new'], [$newPickup]);
        $this->assertSearch(['q' => 'awa', 'status' => 'new'], [$newPickup, $newDelivery]);
        $this->assertSearch(['q' => 'awa', 'status' => 'new', 'fulfillment_type' => 'delivery'], [$newDelivery]);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders?fulfillment_type=drone")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fulfillment_type');
    }

    public function test_invented_confirmation_is_blocked_and_never_returned(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse("Votre commande est enregistrée ! ✓\n\n*Référence : 871087*\n*Total : 16 000 FCFA*"))
            ->push($this->textResponse("C'est noté, votre commande est confirmée 😊")),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertSame('Je vérifie ça et je reviens vers vous très vite 😊', $result['answer']);
        $this->assertTrue($result['should_escalate']);
        $this->assertTrue($result['tool_trace']['blocked_claim']);
        $this->assertSame(0, Order::count());
        Http::assertSentCount(2);

        // Le nouvel essai reçoit la réponse bloquée puis la consigne de correction, outil toujours disponible.
        $retry = Http::recorded()[1][0]->data();
        $this->assertSame('create_order', $retry['tools'][0]['name']);
        $this->assertStringContainsString('871087', $retry['messages'][3]['content'][0]['text']);
        $this->assertStringContainsString('[Message système, pas du client', $retry['messages'][4]['content']);
        $this->assertStringContainsString('référence sans commande correspondante : 871087', $retry['messages'][4]['content']);
    }

    public function test_blocked_confirmation_retry_can_record_the_order(): void
    {
        $this->seedRecapConversation();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse('Votre commande est bien enregistrée ✅'))
            ->push($this->toolUseResponse([
                'items' => [['name' => 'Pagne wax', 'options' => 'bleu', 'quantity' => 2, 'unit_price' => 7500]],
                'fulfillment_type' => 'delivery',
                'customer_name' => 'Awa',
                'delivery_city' => 'Ouagadougou',
                'delivery_address' => 'Secteur 15',
                'payment_method' => 'Orange Money',
            ]))
            ->push($this->textResponse('Votre commande est enregistrée ✅ Total : 15 000 FCFA.')),
        ]);

        $result = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $order = Order::sole();
        $this->assertSame('Votre commande est enregistrée ✅ Total : 15 000 FCFA.', $result['answer']);
        $this->assertFalse($result['should_escalate']);
        $this->assertSame([['id' => $order->id, 'reference' => $order->reference()]], $result['tool_trace']['orders']);
        $this->assertArrayNotHasKey('blocked_claim', $result['tool_trace']);
        Http::assertSentCount(3);
        $this->assertStringContainsString(
            'commande annoncée comme enregistrée sans appel réussi à create_order',
            Http::recorded()[1][0]->data()['messages'][4]['content'],
        );
    }

    public function test_reference_of_an_existing_order_is_allowed(): void
    {
        $existing = $this->makeOrder('confirmed');
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse(
            "Votre commande est bien enregistrée sous la référence {$existing->reference()} 😊"
        ))]);

        $result = $this->service()->answer($this->business, 'Où en est ma commande ?', $this->conversationId);

        $this->assertSame("Votre commande est bien enregistrée sous la référence {$existing->reference()} 😊", $result['answer']);
        $this->assertFalse($result['should_escalate']);
        Http::assertSentCount(1);
    }

    public function test_letter_only_reference_is_recognised(): void
    {
        $order = $this->makeOrder('new');
        DB::table('orders')->where('id', $order->id)->update(['id' => '01a11866-0000-7000-8000-000000dafacf']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse('Votre commande DAFACF est bien enregistrée, référence DAFACF.'))
            ->push($this->textResponse('Votre commande est confirmée, référence FACADE.'))
            ->push($this->textResponse('Pouvez-vous me rappeler votre nom ?')),
        ]);

        $allowed = $this->service()->answer($this->business, 'Où en est ma commande ?', $this->conversationId);
        $blocked = $this->service()->answer($this->business, 'Où en est ma commande ?', $this->conversationId);

        $this->assertSame('Votre commande DAFACF est bien enregistrée, référence DAFACF.', $allowed['answer']);
        $this->assertSame('Pouvez-vous me rappeler votre nom ?', $blocked['answer']);
        $this->assertStringContainsString('référence sans commande correspondante : FACADE', Http::recorded()[2][0]->data()['messages'][2]['content']);
    }

    public function test_reference_of_another_conversation_order_is_blocked(): void
    {
        $otherConversation = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $otherConversation, 'business_id' => $this->business->id, 'customer_phone' => '22670000009']);
        $foreign = $this->makeOrder('new', ['conversation_id' => $otherConversation]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->textResponse("Votre commande {$foreign->reference()} est confirmée."))
            ->push($this->textResponse('Pouvez-vous me rappeler votre nom ?')),
        ]);

        $result = $this->service()->answer($this->business, 'Où en est ma commande ?', $this->conversationId);

        $this->assertSame('Pouvez-vous me rappeler votre nom ?', $result['answer']);
        Http::assertSentCount(2);
    }

    public function test_recap_questions_and_future_tense_are_not_treated_as_claims(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse(
            "Voici le récapitulatif de votre commande :\n2 × Pagne wax : 15 000 FCFA\nVotre commande sera enregistrée après votre confirmation. Je confirme la commande ?"
        ))]);

        $result = $this->service()->answer($this->business, 'Je veux 2 pagnes wax', $this->conversationId);

        $this->assertFalse($result['should_escalate']);
        Http::assertSentCount(1);
    }

    public function test_history_marks_real_and_invented_confirmations(): void
    {
        $real = $this->makeOrder('confirmed');
        foreach ([
            ['inbound', 'Oui', null],
            ['outbound', "Votre commande est enregistrée ! Référence : {$real->reference()}", null],
            ['inbound', 'Oui', null],
            ['outbound', 'Votre commande est enregistrée ! Référence : 871087', null],
            ['inbound', 'Je veux aussi un sac', null],
            ['outbound', 'Commande enregistrée ✅', json_encode(['orders' => [['id' => 'x', 'reference' => 'ABC123']]])],
        ] as $index => [$direction, $content, $metadata]) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversationId,
                'direction' => $direction,
                'content' => $content,
                'metadata' => $metadata,
                'created_at' => now()->subMinutes(20 - $index),
                'updated_at' => now()->subMinutes(20 - $index),
            ]);
        }
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Avec plaisir !'))]);

        $this->service()->answer($this->business, 'Merci, et pour la livraison ?', $this->conversationId);

        $messages = Http::recorded()[0][0]->data()['messages'];
        $this->assertStringEndsWith("[Note système : commande {$real->reference()} réellement enregistrée par l'outil create_order.]", $messages[1]['content']);
        $this->assertStringEndsWith("[Note système : aucune commande n'a été enregistrée pour ce message ; la référence 871087 n'existe pas. Ne t'en sers pas comme modèle.]", $messages[3]['content']);
        $this->assertStringEndsWith("[Note système : commande ABC123 réellement enregistrée par l'outil create_order.]", $messages[5]['content']);
    }

    public function test_system_notes_never_reach_the_customer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse(
            "Avec plaisir !\n[Note système : aucune commande n'a été enregistrée pour ce message.]"
        ))]);

        $result = $this->service()->answer($this->business, 'Merci pour les infos sur le pagne', $this->conversationId);

        $this->assertSame('Avec plaisir !', $result['answer']);
    }

    public function test_prompt_covers_pending_recaps_and_delivery_to_documented_cities(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textResponse('Très bien.'))]);

        $this->service()->answer($this->business, 'Je veux un pagne wax à Bobo', $this->conversationId);
        $system = Http::recorded()[0][0]->data()['system'];

        $this->assertStringContainsString('ajouter cela à sa commande en cours ou remplacer sa commande', $system);
        $this->assertStringContainsString("traite la commande comme une livraison (delivery) vers cette ville", $system);
        $this->assertStringContainsString('ne réutilise jamais celle d\'une commande précédente', $system);

        // Avec l'expédition activée, la consigne de repli n'a plus lieu d'être.
        $this->business->update(['shipping_enabled' => true]);
        $this->service()->answer($this->business, 'Je veux un pagne wax à Bobo', $this->conversationId);

        $this->assertStringNotContainsString('traite la commande comme une livraison (delivery) vers cette ville', Http::recorded()[1][0]->data()['system']);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<int, Order>  $expected
     */
    private function assertSearch(array $query, array $expected): void
    {
        $ids = $this->getJson("/api/v1/businesses/{$this->business->id}/orders?".http_build_query($query))
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing(array_map(fn (Order $order) => $order->id, $expected), $ids, 'Recherche : '.json_encode($query));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertOrderRejected(array $input, string $message): void
    {
        try {
            app(OrderService::class)->createFromAi($this->business, $this->conversation(), $input);
            $this->fail('La commande aurait dû être refusée : '.$message);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function conversation(): \App\Models\Conversation
    {
        return \App\Models\Conversation::findOrFail($this->conversationId);
    }

    /**
     * Service réel, avec la recherche RAG (pgvector) remplacée par un contexte fixe.
     */
    private function service(): AIResponseService
    {
        return new class(app(EmbeddingService::class), app(OrderService::class), self::CONTEXT) extends AIResponseService
        {
            public function __construct(EmbeddingService $embeddingService, OrderService $orderService, private string $fixedContext)
            {
                parent::__construct($embeddingService, $orderService);
            }

            public function findRelevantContext(Business $business, string $question, int $limit = 5): Collection
            {
                return collect([(object) ['id' => 'chunk-1', 'content' => $this->fixedContext, 'similarity' => 0.9]]);
            }

            public function findRelevantMedia(Business $business, string $question, int $limit = 3): Collection
            {
                return collect();
            }
        };
    }

    private function seedRecapConversation(): void
    {
        $messages = [
            ['inbound', 'Je veux 2 pagnes wax bleus, livraison secteur 15 à Ouaga, je paie par Orange Money. Awa Ouédraogo.'],
            ['outbound', "Récapitulatif :\n2 × Pagne wax bleu à 7 500 FCFA\nFrais de livraison : 1 000 FCFA\nTotal : 16 000 FCFA\nJe confirme la commande ?"],
            ['inbound', 'oui'],
        ];

        foreach ($messages as $index => [$direction, $content]) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversationId,
                'direction' => $direction,
                'sender_type' => $direction === 'inbound' ? 'customer' : 'ai',
                'content' => $content,
                'created_at' => now()->subMinutes(10 - $index),
                'updated_at' => now()->subMinutes(10 - $index),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toolUseResponse(array $input): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => "J'enregistre votre commande."],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_order', 'input' => $input],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(string $status, array $overrides = []): Order
    {
        return Order::create($overrides + [
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
            'items' => [['name' => 'Pagne wax', 'options' => null, 'quantity' => 1, 'unit_price' => 7500]],
            'total_amount' => 7500,
            'delivery_city' => 'Ouagadougou',
            'delivery_address' => 'Secteur 15',
            'payment_method' => 'Orange Money',
            'fulfillment_type' => 'delivery',
            'status' => $status,
        ]);
    }
}
