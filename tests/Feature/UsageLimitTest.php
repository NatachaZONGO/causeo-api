<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Escalation;
use App\Models\LearnedResponse;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\LearningService;
use App\Services\Billing\UsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class UsageLimitTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00:00');
        $this->createConversationSchema();

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('title');
            $table->string('file_path');
            $table->string('file_type');
            $table->integer('file_size')->default(0);
            $table->string('status')->default('pending');
            $table->integer('chunk_count')->default(0);
            $table->timestamps();
        });
        Schema::create('escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('message_id')->nullable();
            $table->uuid('business_id')->nullable();
            $table->text('customer_question');
            $table->text('human_response')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('learned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('escalation_id')->nullable();
            $table->text('question');
            $table->text('answer');
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id,
            'user_id' => $this->owner->id,
            'name' => 'Joyce',
            'modules' => '["orders","appointments"]',
            'whatsapp_phone_number_id' => 'PNID-1',
            'whatsapp_token' => Crypt::encryptString('business-token'),
            'whatsapp_verified' => true,
        ]);
        // Sans abonnement : formule Gratuit.
        $this->business = Business::findOrFail($id);

        Http::fake();
        config(['services.whatsapp_bridge.token' => 'bridge-token']);
    }

    // --- Gratuit : limite quotidienne -----------------------------------------------

    public function test_free_plan_stops_replying_after_10_replies_and_notifies_once_a_day(): void
    {
        $this->addBotReplies(9);
        $this->assertTrue($this->usage()->allowsReply($this->business));

        $this->addBotReplies(1);
        $usage = $this->usage()->usage($this->business);
        $this->assertSame([10, 10, 'day'], [$usage->limit, $usage->used, $usage->window]);
        $this->assertFalse($this->usage()->allowsReply($this->business));

        $this->receive('22670000001', 'Bonjour, vous avez des sacs ?');

        $inbound = DB::table('messages')->where('direction', 'inbound')->sole();
        $this->assertSame(UsageService::LIMIT_REACHED, $inbound->status);
        $this->assertSame('Bonjour, vous avez des sacs ?', $inbound->content);
        $this->assertSame(10, DB::table('messages')->where('direction', 'outbound')->count());
        Http::assertNothingSent();

        $notification = Notification::sole();
        $this->assertSame('billing', $notification->type);
        $this->assertSame('1 client sans réponse automatique aujourd\'hui', $notification->title);
        $this->assertSame(
            "Vos 10 réponses automatiques du jour sont utilisées : 1 client n'a pas reçu de réponse automatique aujourd'hui. "
            ."Répondez-leur depuis WhatsApp, ou passez en Starter pour que l'assistant réponde à tous vos clients.",
            $notification->body,
        );
        $this->assertSame(['kind' => 'replies_missed', 'date' => '2026-10-09', 'missed_customers' => 1, 'plan' => 'free'], $notification->data);

        // Un autre client : même notification, compteur mis à jour. Le même client : rien ne change.
        $this->receive('22670000002', 'Prix du pagne ?');
        $this->receive('22670000001', 'Allô ?');

        $notification = Notification::sole();
        $this->assertSame('2 clients sans réponse automatique aujourd\'hui', $notification->title);
        $this->assertStringContainsString("2 clients n'ont pas reçu de réponse automatique", $notification->body);
        $this->assertSame(2, $notification->data['missed_customers']);
        $this->assertSame(3, DB::table('messages')->where('status', UsageService::LIMIT_REACHED)->count());
    }

    public function test_only_ai_generated_replies_count_not_canned_greetings(): void
    {
        $this->addBotReplies(8, metadata: ['context_used' => ['chunk-1']]);
        $this->addBotReplies(1, metadata: ['context_used' => [], 'escalated' => true]); // message d'attente d'une escalade
        $this->addBotReplies(5, metadata: ['canned' => true]); // « Bonjour… », « Avec plaisir ! »

        $usage = $this->usage()->usage($this->business);
        $this->assertSame(9, $usage->used);
        $this->assertTrue($this->usage()->allowsReply($this->business));

        // Une réponse toute faite ne fait aucun appel à Claude et se signale comme telle.
        foreach (['Bonjour', 'Merci beaucoup'] as $message) {
            $result = (new FixedContextAIResponseService('Contexte'))->answer($this->business, $message);
            $this->assertTrue($result['canned'], $message);
        }
        Http::assertNothingSent();

        // Une réponse de l'IA compte : la limite est atteinte.
        $this->addBotReplies(1, metadata: ['context_used' => ['chunk-1']]);
        $this->assertSame(10, $this->usage()->usage($this->business)->used);
        $this->assertFalse($this->usage()->allowsReply($this->business));
    }

    public function test_daily_limit_resets_at_midnight_in_ouagadougou(): void
    {
        $this->travelTo('2026-10-09 23:30:00');
        $this->addBotReplies(10);

        $this->travelTo('2026-10-09 23:59:59');
        $this->assertFalse($this->usage()->allowsReply($this->business));
        $this->receive('22670000001', 'Bonsoir');

        $this->travelTo('2026-10-10 00:00:00');
        $usage = $this->usage()->usage($this->business);
        $this->assertTrue($this->usage()->allowsReply($this->business));
        $this->assertSame(0, $usage->used);
        $this->assertSame('2026-10-10T00:00:00+00:00', $usage->startsAt->toIso8601String());
        $this->assertSame('2026-10-11T00:00:00+00:00', $usage->resetsAt->toIso8601String());

        // Le lendemain, une nouvelle notification quotidienne.
        $this->addBotReplies(10);
        $this->receive('22670000003', 'Bonjour');
        $this->assertSame(['2026-10-09', '2026-10-10'], Notification::query()->orderBy('created_at')->get()->pluck('data.date')->all());
    }

    // --- Formules payantes : limite mensuelle ---------------------------------------

    public function test_paid_plan_notifies_at_80_and_100_percent_then_stops_replying(): void
    {
        $this->addBotReplies(50, now()->subDay()); // avant la période : non comptées
        $this->assignPlan($this->business->id, 'starter');

        $this->addBotReplies(599);
        $this->usage()->afterReply($this->business);
        $this->assertSame(0, Notification::count());

        $this->addBotReplies(1);
        $this->usage()->afterReply($this->business);
        $this->usage()->afterReply($this->business);

        $alert = Notification::sole();
        $this->assertSame('80 % de vos réponses automatiques utilisées', $alert->title);
        $this->assertSame(
            'Vous avez utilisé 600 des 750 réponses de votre formule Starter (période jusqu\'au 08/11/2026). '
            .'Une fois la limite atteinte, l\'assistant ne répondra plus automatiquement jusqu\'au renouvellement. '
            .'Passez en Pro pour que l\'assistant continue de répondre à tous vos clients.',
            $alert->body,
        );
        $this->assertSame('usage_80', $alert->data['kind']);

        $this->addBotReplies(150);
        $this->assertFalse($this->usage()->allowsReply($this->business));
        $this->usage()->afterReply($this->business);
        $this->usage()->afterReply($this->business);
        $this->assertSame(1, Notification::where('data->kind', 'usage_80')->count());
        $this->assertSame(1, Notification::where('data->kind', 'usage_100')->count());
        $this->assertSame('Toutes vos réponses automatiques sont utilisées', Notification::where('data->kind', 'usage_100')->value('title'));

        $this->receive('22670000001', 'Bonjour');
        $missed = Notification::where('data->kind', 'replies_missed')->sole();
        $this->assertStringStartsWith('Les 750 réponses de votre formule Starter sont utilisées jusqu\'au 08/11/2026 : 1 client n\'a pas reçu', $missed->body);
        $this->assertStringEndsWith("passez en Pro pour que l'assistant continue de répondre.", $missed->body);
        Http::assertNothingSent();

        // Nouvelle période : le compteur repart et les seuils peuvent de nouveau être notifiés.
        $this->travelTo('2026-11-08 10:00:01');
        $this->assignPlan($this->business->id, 'starter');
        $this->assertTrue($this->usage()->allowsReply($this->business));
        $this->assertSame(0, $this->usage()->usage($this->business)->used);
    }

    public function test_trial_uses_the_pro_limit_over_the_trial_period(): void
    {
        DB::table('trial_usages')->delete();
        app(\App\Services\Billing\BillingService::class)->startTrial($this->business, $this->owner);

        $usage = $this->usage()->usage($this->business->fresh());
        $this->assertSame([2000, 'period'], [$usage->limit, $usage->window]);
        $this->assertSame('2026-10-09T10:00:00+00:00', $usage->startsAt->toIso8601String());
        $this->assertSame('2026-11-08T10:00:00+00:00', $usage->resetsAt->toIso8601String());
    }

    public function test_internal_plan_has_no_limit(): void
    {
        $this->assignPlan($this->business->id, 'internal');
        $this->addBotReplies(50);

        $usage = $this->usage()->usage($this->business);
        $this->assertTrue($usage->isUnlimited());
        $this->assertTrue($this->usage()->allowsReply($this->business));
    }

    public function test_express_bridge_also_respects_the_limit(): void
    {
        $this->addBotReplies(10);

        $this->postJson('/api/webhook/whatsapp-express', [
            'business_id' => $this->business->id,
            'from' => '22670000001',
            'text' => 'Bonjour',
            'message_id' => 'wamid.express-1',
        ], ['X-Bridge-Token' => 'bridge-token'])
            ->assertOk()
            ->assertJson(['answer' => null, 'limit_reached' => true, 'should_escalate' => false]);

        $this->assertSame(UsageService::LIMIT_REACHED, DB::table('messages')->where('whatsapp_message_id', 'wamid.express-1')->value('status'));
        Http::assertNothingSent();
    }

    public function test_billing_endpoint_returns_the_usage_counter(): void
    {
        $this->addBotReplies(3);
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.usage', [
                'limit' => 10,
                'used' => 3,
                'remaining' => 7,
                'percent' => 30,
                'window' => 'day',
                'starts_at' => '2026-10-09T00:00:00+00:00',
                'resets_at' => '2026-10-10T00:00:00+00:00',
            ]);
    }

    // --- Gratuit : modules, documents, apprentissage -------------------------------

    public function test_free_plan_keeps_modules_read_only(): void
    {
        $this->assertTrue($this->business->moduleEnabled('orders'));
        $this->assertFalse($this->business->hasModule('orders'));
        $this->assertFalse($this->business->hasModule('appointments'));

        $order = Order::create([
            'business_id' => $this->business->id, 'customer_phone' => '22670000001', 'items' => [['name' => 'Sac', 'quantity' => 1, 'unit_price' => 5000]],
            'total_amount' => 5000, 'fulfillment_type' => 'pickup', 'status' => 'new',
        ]);
        $appointment = Appointment::create([
            'business_id' => $this->business->id, 'customer_phone' => '22670000001', 'service' => 'Retouche',
            'requested_date' => '2026-10-12', 'requested_time' => '15h', 'status' => 'requested',
        ]);
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}/orders")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre formule Gratuit ne permet pas de gérer les commandes : passez en Pro pour les traiter.');

        $this->getJson("/api/v1/businesses/{$this->business->id}/appointments")->assertOk()->assertJsonPath('total', 1);
        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre formule Gratuit ne permet pas de gérer les rendez-vous : passez en Pro pour les traiter.');

        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame('requested', $appointment->fresh()->status);

        // En Pro, la même commande se traite.
        $this->assignPlan($this->business->id, 'pro');
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
    }

    public function test_free_plan_blocks_a_second_document(): void
    {
        Storage::fake('supabase_documents');
        Queue::fake();
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/businesses/{$this->business->id}/documents", ['file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')])
            ->assertCreated();

        $this->postJson("/api/v1/businesses/{$this->business->id}/documents", ['file' => UploadedFile::fake()->create('tarifs.pdf', 10, 'application/pdf')])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre formule Gratuit permet 1 document : passez en Starter pour en ajouter d\'autres.');

        $this->assertSame(1, DB::table('documents')->count());

        $this->assignPlan($this->business->id, 'starter');
        $this->postJson("/api/v1/businesses/{$this->business->id}/documents", ['file' => UploadedFile::fake()->create('tarifs.pdf', 10, 'application/pdf')])
            ->assertCreated();
    }

    public function test_free_plan_does_not_learn_from_the_owner(): void
    {
        $conversation = $this->conversation('22670000001');
        $escalation = Escalation::create([
            'conversation_id' => $conversation, 'business_id' => $this->business->id,
            'customer_question' => 'Vous livrez à Kaya ?', 'human_response' => 'Oui, en 48 h.', 'status' => 'answered',
        ]);

        app(LearningService::class)->learnFromEscalation($escalation);

        $this->assertSame(0, LearnedResponse::count());
    }

    // --- Outils ---------------------------------------------------------------------

    private function usage(): UsageService
    {
        return app(UsageService::class);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function addBotReplies(int $count, ?\DateTimeInterface $at = null, ?array $metadata = null): void
    {
        $conversation = $this->conversation('22600000000');
        $at ??= now();

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversation,
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'content' => 'Réponse',
                'status' => 'sent',
                'metadata' => $metadata === null ? null : json_encode($metadata),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('messages')->insert($chunk);
        }
    }

    private function conversation(string $phone): string
    {
        $existing = DB::table('conversations')->where('business_id', $this->business->id)->where('customer_phone', $phone)->value('id');
        if ($existing) {
            return $existing;
        }

        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $this->business->id, 'customer_phone' => $phone]);

        return $id;
    }

    private function receive(string $from, string $text): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-1'],
                        'contacts' => [['profile' => ['name' => 'Client'], 'wa_id' => $from]],
                        'messages' => [['from' => $from, 'id' => 'wamid.'.Str::random(12), 'type' => 'text', 'text' => ['body' => $text]]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }
}
