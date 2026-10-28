<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\LearnedResponse;
use App\Models\User;
use App\Services\AI\LearningService;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class EscalationLifecycleTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 12:00:00');
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_23_130000_add_resolved_and_expired_escalation_statuses.php'))->up();
        Schema::create('learned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('escalation_id')->nullable();
            $table->text('question');
            $table->text('answer');
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        // Pas d'appel à Gemini (l'enregistrement du vecteur échoue ensuite sous SQLite, sans gêner le test).
        $this->app->instance(EmbeddingService::class, new class extends EmbeddingService
        {
            public function __construct()
            {
            }

            public function embed(string $text): array
            {
                return [0.1, 0.2];
            }
        });

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $this->business = $this->newBusiness();
        $this->conversation = $this->newConversation();
    }

    // --- Réponse du gérant ------------------------------------------------------------------------

    public function test_an_answer_closes_every_pending_escalation_and_learns_only_the_latest(): void
    {
        $old = $this->escalate('Vous livrez à Bobo ?', hoursAgo: 3);
        $middle = $this->escalate('Et à Koudougou ?', hoursAgo: 2);
        $latest = $this->escalate('Combien la livraison ?', hoursAgo: 1);
        $other = $this->escalate('Autre conversation', hoursAgo: 1, conversation: $this->newConversation());

        app(LearningService::class)->answerPendingEscalation($this->conversation, 'Oui, partout : 1 500 FCFA.');

        foreach ([$old, $middle, $latest] as $escalation) {
            $escalation->refresh();
            $this->assertSame(Escalation::ANSWERED, $escalation->status);
            $this->assertSame('Oui, partout : 1 500 FCFA.', $escalation->human_response);
            $this->assertNotNull($escalation->answered_at);
        }
        $this->assertSame(Escalation::PENDING, $other->fresh()->status);
        $this->assertSame([$latest->id], LearnedResponse::pluck('escalation_id')->all());
    }

    public function test_a_dashboard_answer_to_a_given_escalation_learns_that_one(): void
    {
        $asked = $this->escalate('Vous livrez à Bobo ?', hoursAgo: 3);
        $this->escalate('Et à Koudougou ?', hoursAgo: 1);

        app(LearningService::class)->answerPendingEscalation($this->conversation, 'Oui, à Bobo.', target: $asked);

        $this->assertSame(0, Escalation::where('status', Escalation::PENDING)->count());
        $this->assertSame([$asked->id], LearnedResponse::pluck('escalation_id')->all());
    }

    public function test_an_answer_after_24_hours_closes_without_learning(): void
    {
        $stale = $this->escalate('Vous livrez à Bobo ?', hoursAgo: 30);

        app(LearningService::class)->answerPendingEscalation($this->conversation, 'Oui.');

        $this->assertSame(Escalation::ANSWERED, $stale->fresh()->status);
        $this->assertSame(0, LearnedResponse::count());
    }

    // --- Expiration ------------------------------------------------------------------------------------

    public function test_pending_escalations_expire_after_24_hours_without_learning(): void
    {
        $stale = $this->escalate('Vieille question', hoursAgo: 25);
        $recent = $this->escalate('Question récente', hoursAgo: 23);
        $answered = $this->escalate('Déjà répondue', hoursAgo: 48, status: Escalation::ANSWERED);

        $this->artisan('escalations:expire')
            ->expectsOutputToContain('1 escalade(s) en attente depuis plus de 24 h expirée(s).')
            ->assertSuccessful();

        $this->assertSame(Escalation::EXPIRED, $stale->fresh()->status);
        $this->assertNull($stale->fresh()->human_response);
        $this->assertSame(Escalation::PENDING, $recent->fresh()->status);
        $this->assertSame(Escalation::ANSWERED, $answered->fresh()->status);
        $this->assertSame(0, LearnedResponse::count());
    }

    public function test_expiry_runs_every_hour(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'escalations:expire'));

        $this->assertNotNull($event);
        $this->assertSame('10 * * * *', $event->expression);
    }

    public function test_cleanup_keeps_only_the_latest_pending_escalation_per_conversation(): void
    {
        $this->escalate('Très vieille', hoursAgo: 40);
        $first = $this->escalate('Première', hoursAgo: 5);
        $second = $this->escalate('Deuxième', hoursAgo: 3);
        $latest = $this->escalate('Dernière', hoursAgo: 1);
        $alone = $this->escalate('Seule de sa conversation', hoursAgo: 2, conversation: $this->newConversation());

        $this->artisan('escalations:expire', ['--cleanup' => true])
            ->expectsOutputToContain('1 escalade(s) en attente depuis plus de 24 h expirée(s).')
            ->expectsOutputToContain('2 escalade(s) plus ancienne(s)')
            ->assertSuccessful();

        $this->assertSame(Escalation::EXPIRED, $first->fresh()->status);
        $this->assertSame(Escalation::EXPIRED, $second->fresh()->status);
        $this->assertSame(Escalation::PENDING, $latest->fresh()->status);
        $this->assertSame(Escalation::PENDING, $alone->fresh()->status);
        $this->assertSame(2, Escalation::where('status', Escalation::PENDING)->count());
    }

    // --- Marquer comme traitée ---------------------------------------------------------------------------

    public function test_owner_marks_an_escalation_as_handled_without_answering(): void
    {
        $escalation = $this->escalate('Vous livrez à Bobo ?', hoursAgo: 1);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/escalations/{$escalation->id}/resolve")
            ->assertOk()
            ->assertJsonPath('escalation.status', 'resolved');

        $this->assertSame(Escalation::RESOLVED, $escalation->fresh()->status);
        $this->assertNull($escalation->fresh()->human_response);
        $this->assertSame(0, LearnedResponse::count());

        $this->postJson("/api/v1/escalations/{$escalation->id}/resolve")->assertUnprocessable();
    }

    public function test_only_the_owner_can_mark_an_escalation_as_handled(): void
    {
        $escalation = $this->escalate('Vous livrez à Bobo ?', hoursAgo: 1);
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));

        $this->postJson("/api/v1/escalations/{$escalation->id}/resolve")->assertForbidden();
        $this->assertSame(Escalation::PENDING, $escalation->fresh()->status);
    }

    // --- Outils --------------------------------------------------------------------------------------------

    private function escalate(string $question, int $hoursAgo, ?Conversation $conversation = null, string $status = Escalation::PENDING): Escalation
    {
        $conversation ??= $this->conversation;
        $id = (string) Str::uuid();
        $at = now()->subHours($hoursAgo);

        // Insertion directe : pas de notification, et la date de création voulue.
        DB::table('escalations')->insert([
            'id' => $id, 'conversation_id' => $conversation->id, 'business_id' => $this->business->id,
            'customer_question' => $question, 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return Escalation::findOrFail($id);
    }

    private function newBusiness(): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]']);
        $this->assignPlan($id);

        return Business::findOrFail($id);
    }

    private function newConversation(): Conversation
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $this->business->id, 'customer_phone' => '2267'.random_int(1000000, 9999999)]);

        return Conversation::findOrFail($id);
    }
}
