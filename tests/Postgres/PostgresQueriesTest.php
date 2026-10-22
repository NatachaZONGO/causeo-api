<?php

namespace Tests\Postgres;

use App\Models\Business;
use App\Models\Escalation;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\AI\AiCostService;
use App\Services\AI\ManagerReplyService;
use App\Services\AppointmentService;
use App\Services\Billing\UsageService;
use App\Services\Embedding\EmbeddingService;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Listes et requêtes complexes sur PostgreSQL (voir PostgresTestCase).
 */
class PostgresQueriesTest extends PostgresTestCase
{
    private int $owner;

    private string $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-24 12:00:00');
        (new PlanSeeder)->run();
        $this->owner = $this->user();
        $this->business = $this->business($this->owner);
    }

    // --- Conversations ------------------------------------------------------------------------------

    public function test_conversation_lists_carry_the_latest_message_with_uuid_ids(): void
    {
        $awa = $this->conversation($this->business, 'Awa', lastReadAt: '2026-10-24 09:00:00');
        $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 08:00:00');
        // Deux messages à la même seconde : le plus grand id l'emporte (là où latestOfMany faisait MAX(uuid)).
        $this->message($awa, 'outbound', 'Premier', '2026-10-24 10:00:00', '00000000-0000-4000-8000-000000000001');
        $this->message($awa, 'inbound', 'Second', '2026-10-24 10:00:00', '00000000-0000-4000-8000-000000000002');
        $first = $this->message($awa, 'inbound', 'Vous livrez ?', '2026-10-24 07:00:00');
        $this->escalation($awa, $this->business, $first, 'Vous livrez ?');

        $fatou = $this->conversation($this->business, 'Fatou', lastReadAt: '2026-10-24 11:00:00');
        $this->message($fatou, 'inbound', 'Bonsoir', '2026-10-24 08:30:00');

        Sanctum::actingAs(User::findOrFail($this->owner));
        $data = $this->getJson("/api/v1/businesses/{$this->business}/conversations")->assertOk()->json('data');
        $latest = collect($data)->mapWithKeys(fn ($c) => [$c['customer_name'] => $c['latest_message']['content']]);
        $this->assertSame(['Awa' => 'Second', 'Fatou' => 'Bonsoir'], $latest->sortKeys()->all());

        $this->assertSame(['Awa'], array_column($this->getJson("/api/v1/businesses/{$this->business}/conversations?filter=unread")->assertOk()->json('data'), 'customer_name'));
        $this->assertSame(['Awa'], array_column($this->getJson("/api/v1/businesses/{$this->business}/conversations?filter=pending")->assertOk()->json('data'), 'customer_name'));
        $this->assertSame(['Fatou'], array_column($this->getJson("/api/v1/businesses/{$this->business}/conversations?q=bonsoir")->assertOk()->json('data'), 'customer_name'));

        Sanctum::actingAs(User::findOrFail($this->user('Admin', admin: true)));
        $admin = $this->getJson('/api/v1/admin/conversations')->assertOk()->json('data');
        $this->assertCount(2, $admin);
        $awaRow = collect($admin)->firstWhere('customer_name', 'Awa');
        $this->assertSame('Second', $awaRow['latest_message']['content']);
        $this->assertSame('Joyce Boutique', $awaRow['business_name']);
        $this->assertTrue($awaRow['has_pending_escalation']);
    }

    public function test_conversation_messages_are_paginated_by_date_then_id(): void
    {
        $awa = $this->conversation($this->business, 'Awa');
        for ($i = 1; $i <= 5; $i++) {
            $this->message($awa, 'inbound', "Message {$i}", '2026-10-24 10:00:00', "00000000-0000-4000-8000-00000000000{$i}");
        }

        Sanctum::actingAs(User::findOrFail($this->owner));
        $page = $this->getJson("/api/v1/conversations/{$awa}?limit=2")->assertOk()->json('conversation');
        $this->assertSame(['Message 4', 'Message 5'], array_column($page['messages'], 'content'));
        $this->assertTrue($page['has_older_messages']);

        $older = $this->getJson("/api/v1/conversations/{$awa}?limit=2&before={$page['messages'][0]['id']}")->assertOk()->json('conversation');
        $this->assertSame(['Message 2', 'Message 3'], array_column($older['messages'], 'content'));

        $this->getJson("/api/v1/conversations/{$awa}?before=pas-un-uuid")->assertUnprocessable();
    }

    // --- Admin ----------------------------------------------------------------------------------------

    public function test_admin_pages_run_on_postgresql(): void
    {
        $awa = $this->conversation($this->business, 'Awa');
        $message = $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $this->escalation($awa, $this->business, $message, 'Bonjour ?');

        Sanctum::actingAs(User::findOrFail($this->user('Admin', admin: true)));

        foreach (['stats', 'businesses', 'users', 'conversations', 'escalations', 'ai-costs', 'payments', 'notifications', 'feedback'] as $page) {
            $this->getJson("/api/v1/admin/{$page}")->assertOk();
        }
    }

    // --- Coûts et unités (chemins JSON) ----------------------------------------------------------------

    public function test_ai_costs_and_units_read_json_metadata(): void
    {
        config(['services.anthropic.pricing.models.claude-test' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1]]);
        $awa = $this->conversation($this->business, 'Awa');
        $this->message($awa, 'inbound', 'Vocal', '2026-10-24 10:00:00', metadata: [
            'type' => 'audio',
            'ai_usage' => ['transcription' => ['model' => 'gemini-2.5-flash', 'input_audio_tokens' => 384, 'input_text_tokens' => 100, 'output_tokens' => 50]],
        ]);
        $this->message($awa, 'outbound', 'Réponse', '2026-10-24 10:01:00', metadata: [
            'ai_usage' => ['model' => 'claude-test', 'calls' => 1, 'input_tokens' => 1000, 'output_tokens' => 30, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ]);
        $this->message($awa, 'outbound', 'Avec plaisir !', '2026-10-24 10:02:00', metadata: ['canned' => true]);

        $totals = app(AiCostService::class)->month(CarbonImmutable::now())['totals'];
        $this->assertSame(1, $totals['replies']);
        $this->assertSame(1000, $totals['input_tokens']);
        $this->assertSame(1, $totals['transcriptions']);
        $this->assertSame(round(0.00345 + 0.000539, 4), $totals['cost_usd']);

        // La réponse toute faite ne compte pas.
        $this->assertSame(1, app(UsageService::class)->repliesSince(Business::findOrFail($this->business), now()->subDay()));
    }

    // --- pgvector -------------------------------------------------------------------------------------------

    public function test_owner_examples_use_cosine_similarity_and_stay_within_the_business(): void
    {
        $other = $this->business($this->user('Autre'), 'Autre Boutique');
        $this->managerReply($this->business, 'Le wax bleu ?', 'Oui ma sœur 🙏', self::vector(0));
        $this->managerReply($this->business, 'Vous livrez ?', 'Oui partout', self::vector(1));
        $this->managerReply($other, 'Le wax bleu ?', 'SECRET', self::vector(0));

        $examples = app(ManagerReplyService::class)->similarExamples(Business::findOrFail($this->business), self::vector(0));

        $this->assertSame(['Oui ma sœur 🙏'], $examples->pluck('reply')->all());
        $this->assertEqualsWithDelta(1.0, (float) $examples->first()->similarity, 0.0001);
    }

    public function test_relevant_context_reads_document_chunks_and_learned_responses(): void
    {
        $document = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $document, 'business_id' => $this->business, 'title' => 'Catalogue', 'file_path' => 'x', 'file_type' => 'txt', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('INSERT INTO document_chunks (id, document_id, business_id, content, chunk_index, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?::vector, now(), now())',
            [(string) Str::uuid(), $document, $this->business, 'Pagne wax bleu : 7 500 FCFA.', self::vector(0)]);
        DB::statement('INSERT INTO learned_responses (id, business_id, question, answer, usage_count, question_embedding, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?::vector, now(), now())',
            [(string) Str::uuid(), $this->business, 'Livrez-vous à Bobo ?', 'Oui, 2 000 FCFA.', self::vector(0, 0.9)]);
        DB::statement('INSERT INTO learned_responses (id, business_id, question, answer, usage_count, question_embedding, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?::vector, now(), now())',
            [(string) Str::uuid(), $this->business, 'Sans rapport', 'Non', self::vector(2)]);

        $embedding = new class extends EmbeddingService
        {
            public function __construct()
            {
            }

            public function embed(string $text): array
            {
                $values = array_fill(0, 1536, 0.0);
                $values[0] = 1.0;

                return $values;
            }
        };
        $ai = new AIResponseService($embedding, app(OrderService::class), app(AppointmentService::class));

        $context = $ai->findRelevantContext(Business::findOrFail($this->business), 'Le pagne wax bleu ?');

        $this->assertSame(['document', 'learned_response'], $context->pluck('source')->sort()->values()->all());
    }

    // --- Escalades ---------------------------------------------------------------------------------------------

    public function test_escalation_statuses_and_cleanup(): void
    {
        $awa = $this->conversation($this->business, 'Awa');
        $message = $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $old = $this->escalation($awa, $this->business, $message, 'Ancienne', at: '2026-10-24 08:00:00');
        $latest = $this->escalation($awa, $this->business, $message, 'Récente', at: '2026-10-24 11:00:00');
        $stale = $this->escalation($awa, $this->business, $message, 'Vieille', at: '2026-10-22 08:00:00');

        $this->artisan('escalations:expire', ['--cleanup' => true])->assertSuccessful();

        $this->assertSame(Escalation::EXPIRED, Escalation::findOrFail($stale)->status);
        $this->assertSame(Escalation::EXPIRED, Escalation::findOrFail($old)->status);
        $this->assertSame(Escalation::PENDING, Escalation::findOrFail($latest)->status);

        Escalation::findOrFail($latest)->update(['status' => Escalation::RESOLVED]);
        $this->assertSame(Escalation::RESOLVED, Escalation::findOrFail($latest)->status);

        $this->expectException(QueryException::class);
        DB::table('escalations')->where('id', $latest)->update(['status' => 'inconnu']);
    }

    private function managerReply(string $businessId, string $question, string $reply, string $vector): void
    {
        DB::statement('INSERT INTO manager_replies (id, business_id, customer_question, reply, source, question_embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::vector, now(), now())',
            [(string) Str::uuid(), $businessId, $question, $reply, 'dashboard', $vector]);
    }
}
