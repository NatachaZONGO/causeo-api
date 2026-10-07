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
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_07_100000_create_notifications_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_110000_create_orders_table.php'))->up();

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);

        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $businessId, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique']);
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

    public function test_other_users_cannot_access_orders(): void
    {
        $order = $this->makeOrder('new');

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();

        $this->assertSame('new', $order->fresh()->status);
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

    private function makeOrder(string $status): Order
    {
        return Order::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
            'items' => [['name' => 'Pagne wax', 'options' => null, 'quantity' => 1, 'unit_price' => 7500]],
            'total_amount' => 7500,
            'delivery_city' => 'Ouagadougou',
            'delivery_address' => 'Secteur 15',
            'payment_method' => 'Orange Money',
            'status' => $status,
        ]);
    }
}
