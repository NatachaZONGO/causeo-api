<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\AI\AiCostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class AiCostTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private User $admin;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            // Table de prix réelle, plus un modèle de test à 3 $ / 15 $.
            'services.anthropic.pricing.usd_to_xof' => 600.0,
            'services.anthropic.pricing.models.claude-test' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
        ]);
        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        $this->business = $this->makeBusiness('Joyce Boutique', '["orders"]');
    }

    public function test_tokens_of_every_claude_call_are_added_to_the_reply_metadata(): void
    {
        $conversationId = $this->conversation($this->business);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_order', 'input' => [
                        'items' => [['name' => 'Pagne wax', 'options' => 'bleu', 'quantity' => 2, 'unit_price' => 7500]],
                        'customer_name' => 'Awa',
                        'delivery_city' => 'Ouagadougou',
                        'delivery_address' => 'Secteur 15',
                        'fulfillment_type' => 'delivery',
                        'payment_method' => 'Orange Money',
                    ]],
                ],
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'cache_read_input_tokens' => 500],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Votre commande est enregistrée ✅']],
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 40, 'cache_creation_input_tokens' => 300, 'cache_read_input_tokens' => 0],
            ]),
        ]);

        $result = $this->service()->answer($this->business, 'Je confirme 2 pagnes wax bleus, livraison secteur 15, paiement Orange Money.', $conversationId);

        $this->assertSame('Votre commande est enregistrée ✅', $result['answer']);
        $this->assertSame([
            'model' => 'claude-test',
            'calls' => 2,
            'input_tokens' => 2700,
            'output_tokens' => 120,
            'cache_creation_input_tokens' => 300,
            'cache_read_input_tokens' => 500,
        ], $result['tool_trace']['ai_usage']);
    }

    public function test_canned_replies_record_no_tokens(): void
    {
        Http::fake();

        $result = $this->service()->answer($this->business, 'Bonjour', $this->conversation($this->business));

        Http::assertNothingSent();
        $this->assertArrayNotHasKey('ai_usage', $result['tool_trace'] ?? []);
    }

    public function test_admin_sees_the_average_cost_per_reply_and_the_cost_per_business(): void
    {
        $other = $this->makeBusiness('Salon Fatou');
        $joyce = $this->conversation($this->business);
        $fatou = $this->conversation($other);

        // Joyce : 2 réponses à 0,0051 $ (1 000 en entrée, 100 en sortie, 2 000 lus en cache).
        $joyceUsage = ['calls' => 1, 'input_tokens' => 1000, 'output_tokens' => 100, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 2000];
        $this->reply($joyce, $joyceUsage, '2026-10-02 09:00:00');
        $this->reply($joyce, $joyceUsage, '2026-10-19 09:00:00');
        // Fatou : 1 réponse avec outil (2 appels) à 0,01875 $.
        $this->reply($fatou, ['calls' => 2, 'input_tokens' => 4000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 0], '2026-10-10 09:00:00');
        // Hors calcul : septembre, réponse toute faite, message entrant.
        $this->reply($joyce, $joyceUsage, '2026-09-30 23:00:00');
        $this->reply($joyce, null, '2026-10-05 09:00:00');
        DB::table('messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $joyce, 'direction' => 'inbound', 'sender_type' => 'customer',
            'content' => 'Bonjour', 'metadata' => json_encode(['ai_usage' => $joyceUsage]), 'created_at' => '2026-10-05 09:00:00']);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/ai-costs')->assertOk()
            ->assertJsonPath('month', '2026-10')
            ->assertJsonPath('current_model', 'claude-test')
            ->assertJsonPath('pricing.usd_to_xof', 600)
            ->assertJsonPath('pricing.models.claude-haiku-4-5.input', 1)
            ->assertJsonPath('totals.replies', 3)
            ->assertJsonPath('totals.priced_replies', 3)
            ->assertJsonPath('totals.unpriced_replies', 0)
            ->assertJsonPath('totals.price_status', null)
            ->assertJsonPath('totals.calls', 4)
            ->assertJsonPath('totals.input_tokens', 6000)
            ->assertJsonPath('totals.output_tokens', 400)
            ->assertJsonPath('totals.cache_creation_input_tokens', 1000)
            ->assertJsonPath('totals.cache_read_input_tokens', 4000)
            ->assertJsonPath('businesses.0.name', 'Salon Fatou')
            ->assertJsonPath('businesses.0.replies', 1)
            ->assertJsonPath('businesses.1.name', 'Joyce Boutique')
            ->assertJsonPath('businesses.1.replies', 2);

        $this->assertEqualsWithDelta(0.02895, $response->json('totals.cost_usd'), 0.0001);
        $this->assertEquals(17, $response->json('totals.cost_xof'));
        $this->assertEqualsWithDelta(0.00965, $response->json('totals.average_cost_usd'), 0.000001);
        $this->assertEqualsWithDelta(5.79, $response->json('totals.average_cost_xof'), 0.01);
        $this->assertEqualsWithDelta(0.0102, $response->json('businesses.1.cost_usd'), 0.0001);
        $this->assertEqualsWithDelta(3.06, $response->json('businesses.1.average_cost_xof'), 0.01);

        // Historique des 6 derniers mois, du plus ancien au plus récent.
        $history = $response->json('history');
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_column($history, 'month'));
        $this->assertSame([0, 0, 0, 0, 1, 3], array_column($history, 'replies'));
        $this->assertNull($history[0]['average_cost_usd']);

        // Mois demandé.
        $this->getJson('/api/v1/admin/ai-costs?month=2026-09')->assertOk()
            ->assertJsonPath('totals.replies', 1)
            ->assertJsonPath('businesses.0.name', 'Joyce Boutique');
        $this->getJson('/api/v1/admin/ai-costs?month=octobre')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_each_reply_is_priced_with_its_own_model_and_unknown_models_are_not_counted_as_free(): void
    {
        $other = $this->makeBusiness('Salon Fatou');
        $joyce = $this->conversation($this->business);
        $fatou = $this->conversation($other);
        $tokens = ['calls' => 1, 'input_tokens' => 10000, 'output_tokens' => 1000, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];

        // Mêmes tokens, deux modèles : 0,015 $ en Haiku 4.5 (1 $ / 5 $), 0,045 $ en Sonnet 4.5 (3 $ / 15 $).
        $this->reply($joyce, $tokens, '2026-10-02 09:00:00', 'claude-haiku-4-5-20251001');
        $this->reply($joyce, $tokens, '2026-10-03 09:00:00', 'claude-sonnet-4-5-20250929');
        // Modèle absent de la table : « prix inconnu », pas 0.
        $this->reply($fatou, $tokens, '2026-10-04 09:00:00', 'claude-mystere-9');

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/ai-costs')->assertOk()
            ->assertJsonPath('totals.replies', 3)
            ->assertJsonPath('totals.priced_replies', 2)
            ->assertJsonPath('totals.unpriced_replies', 1)
            ->assertJsonPath('totals.price_status', 'prix inconnu')
            ->assertJsonPath('businesses.0.name', 'Joyce Boutique')
            ->assertJsonPath('businesses.0.price_status', null)
            ->assertJsonPath('businesses.1.name', 'Salon Fatou')
            ->assertJsonPath('businesses.1.replies', 1)
            ->assertJsonPath('businesses.1.cost_usd', null)
            ->assertJsonPath('businesses.1.cost_xof', null)
            ->assertJsonPath('businesses.1.average_cost_usd', null)
            ->assertJsonPath('businesses.1.price_status', 'prix inconnu');

        // Total et moyenne sur les seules réponses au prix connu.
        $this->assertEqualsWithDelta(0.06, $response->json('totals.cost_usd'), 0.00001);
        $this->assertEquals(36, $response->json('totals.cost_xof'));
        $this->assertEqualsWithDelta(0.03, $response->json('totals.average_cost_usd'), 0.000001);
        $this->assertEqualsWithDelta(18.0, $response->json('totals.average_cost_xof'), 0.01);

        $models = collect($response->json('totals.models'))->keyBy('model');
        $this->assertEqualsWithDelta(0.015, $models['claude-haiku-4-5-20251001']['cost_usd'], 0.00001);
        $this->assertEqualsWithDelta(0.045, $models['claude-sonnet-4-5-20250929']['cost_usd'], 0.00001);
        $this->assertSame(['model' => 'claude-mystere-9', 'replies' => 1, 'cost_usd' => null, 'cost_xof' => null, 'price_status' => 'prix inconnu'], $models['claude-mystere-9']);

        $history = collect($response->json('history'))->keyBy('month');
        $this->assertSame(1, $history['2026-10']['unpriced_replies']);
        $this->assertEqualsWithDelta(0.06, $history['2026-10']['cost_usd'], 0.00001);
    }

    public function test_model_names_are_matched_on_their_alias(): void
    {
        $costs = app(AiCostService::class);

        $this->assertSame(1.0, $costs->priceFor('claude-haiku-4-5')['input']);
        $this->assertSame(1.0, $costs->priceFor('claude-haiku-4-5-20251001')['input']);
        $this->assertSame(3.0, $costs->priceFor('claude-sonnet-4-20250514')['input']);
        $this->assertSame(0.05, $costs->priceFor('claude-sonnet-5-5')['cache_read']);
        $this->assertSame(2.0, $costs->priceFor('claude-sonnet-5')['input']);
        // Un modèle voisin n'emprunte pas le prix d'un autre.
        $this->assertNull($costs->priceFor('claude-sonnet-5-7'));
        $this->assertNull($costs->priceFor('claude-haiku-4'));
        $this->assertNull($costs->priceFor(null));
    }

    public function test_costs_are_reserved_to_admins(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-costs')->assertForbidden();
    }

    private function service(): FixedContextAIResponseService
    {
        return new FixedContextAIResponseService('Pagne wax : 7 500 FCFA, coloris bleu ou rouge. Livraison à Ouagadougou : 1 000 FCFA.');
    }

    private function makeBusiness(string $name, string $modules = '[]'): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => $name, 'modules' => $modules]);
        $this->assignPlan($id);

        return Business::findOrFail($id);
    }

    private function conversation(Business $business): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $business->id, 'customer_phone' => '22670000001', 'customer_name' => 'Awa']);

        return $id;
    }

    /**
     * @param  array<string, int>|null  $usage  null : réponse toute faite, sans appel à Claude
     */
    private function reply(string $conversationId, ?array $usage, string $at, string $model = 'claude-test'): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'direction' => 'outbound',
            'sender_type' => 'ai',
            'content' => 'Réponse',
            'metadata' => json_encode($usage === null ? ['canned' => true] : ['context_used' => [], 'ai_usage' => ['model' => $model, ...$usage]]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
