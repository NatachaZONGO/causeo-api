<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCustomer;
use App\Jobs\SendCustomerReply;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AIResponseService;
use App\Services\Conversations\ReplyPacing;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FakeWhatsAppService;
use Tests\Support\FakeWhatsAppServiceFactory;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

/**
 * Rythme des réponses : regroupement des messages du client, délai « en train
 * d'écrire », et silence du bot quand le gérant répond pendant l'attente.
 */
class ReplyPacingTest extends TestCase
{
    use CreatesConversationSchema;

    private const CONTEXT = 'Pagne wax : 7 500 FCFA, coloris bleu ou rouge.';

    /** 40 caractères : 3 s + 1 s de délai d'écriture. */
    private const ANSWER = 'Oui, le pagne wax bleu est à 7 500 FCFA.';

    private Business $business;

    /** Réponse de Claude. */
    private string $claudeAnswer = self::ANSWER;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-test',
            'services.reply_pacing.typing_jitter_seconds' => 0,
        ]);
        $this->travelTo('2026-10-24 10:00:00');
        $this->createConversationSchema();

        $owner =User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]',
            'whatsapp_phone_number_id' => 'PNID-1', 'whatsapp_verified' => true,
        ]);
        $this->assignPlan($id);
        $this->business = Business::findOrFail($id);

        FakeWhatsAppService::$sent = [];
        FakeWhatsAppService::$read = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        $this->app->instance(AIResponseService::class, new FixedContextAIResponseService(self::CONTEXT));

        Http::fake(['api.anthropic.com/*' => fn () => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $this->claudeAnswer]],
        ])]);
    }

    public function test_each_message_shows_typing_and_restarts_the_wait_within_30_seconds(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Bonsoir', 'wamid.1');
        $this->travel(5)->seconds();
        $this->receive('Vous avez le pagne bleu ?', 'wamid.2');
        $this->travel(20)->seconds();
        $this->receive('Et en rouge ?', 'wamid.3');

        // 8 s après chaque message, mais au plus 30 s après le premier (reçu il y a 25 s).
        $this->assertSame([8, 8, 5], Queue::pushed(ReplyToCustomer::class)->map(fn (ReplyToCustomer $job) => $job->delay)->all());
        $this->assertSame(
            [['message_id' => 'wamid.1', 'typing' => true], ['message_id' => 'wamid.2', 'typing' => true], ['message_id' => 'wamid.3', 'typing' => true]],
            FakeWhatsAppService::$read,
        );
        Queue::assertNotPushed(SendCustomerReply::class);
        Http::assertNothingSent();
    }

    public function test_a_burst_of_messages_gets_a_single_reply_after_a_typing_delay(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Bonsoir', 'wamid.1');
        $this->travel(4)->seconds();
        $this->receive('Vous avez le pagne bleu ?', 'wamid.2');
        [$first, $last] = Queue::pushed(ReplyToCustomer::class)->all();

        // Le job du premier message voit que le client a continué d'écrire : il ne fait rien.
        $this->travel(4)->seconds();
        $this->runJob($first);
        Http::assertNothingSent();

        $this->travel(8)->seconds();
        $this->runJob($last);

        // Une seule question à Claude, avec les deux messages, sans les répéter dans l'historique.
        Http::assertSentCount(1);
        $request = Http::recorded()[0][0]->data();
        $this->assertCount(1, $request['messages']);
        $this->assertStringEndsWith("Question du client : Bonsoir\nVous avez le pagne bleu ?", $request['messages'][0]['content']);

        // La réponse part 3 s + 1 s par 40 caractères plus tard, « en train d'écrire » relancé entre-temps.
        $send = Queue::pushed(SendCustomerReply::class)->sole();
        $this->assertSame(4, $send->delay);
        $this->assertSame(['message_id' => 'wamid.2', 'typing' => true], end(FakeWhatsAppService::$read));
        $this->assertSame([], FakeWhatsAppService::$sent);

        $this->travel(4)->seconds();
        $this->runJob($send);

        $this->assertSame([self::ANSWER], FakeWhatsAppService::$sent);
        $outbound = Message::where('direction', 'outbound')->sole();
        $this->assertSame('ai', $outbound->sender_type);
        $this->assertSame(2, $outbound->metadata['grouped_messages']);
        $this->assertSame(['answered_by_ai', 'answered_by_ai'], Message::where('direction', 'inbound')->orderBy('created_at')->pluck('status')->all());
        // Une seule unité pour toute la rafale.
        $this->assertSame(1, $this->business->fresh()->monthly_message_count);
    }

    public function test_a_customer_who_keeps_writing_is_answered_30_seconds_after_the_first_message(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Bonjour', 'wamid.1');
        $this->travel(7)->seconds();
        $this->receive('Je cherche un pagne', 'wamid.2');
        $this->travel(7)->seconds();
        $this->receive('en wax', 'wamid.3');
        $this->travel(7)->seconds();
        $this->receive('bleu si possible', 'wamid.4');
        $this->travel(7)->seconds();
        $this->receive('pour samedi', 'wamid.5');
        $jobs = Queue::pushed(ReplyToCustomer::class)->all();

        // Dernier message 28 s après le premier : 2 s d'attente seulement.
        $this->assertSame(2, $jobs[4]->delay);

        $this->travel(2)->seconds();
        $this->runJob($jobs[4]);
        $this->runJob($jobs[3]);

        Http::assertSentCount(1);
        $this->assertStringEndsWith("Bonjour\nJe cherche un pagne\nen wax\nbleu si possible\npour samedi", Http::recorded()[0][0]->data()['messages'][0]['content']);
        Queue::assertPushed(SendCustomerReply::class, 1);
    }

    public function test_an_old_message_left_pending_is_not_added_to_the_question(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Vous êtes ouverts ?', 'wamid.OLD');
        Message::where('whatsapp_message_id', 'wamid.OLD')->update(['created_at' => now()->subDay()]);
        $this->receive('Vous avez le pagne bleu ?', 'wamid.1');

        $this->travel(8)->seconds();
        $this->runJob(Queue::pushed(ReplyToCustomer::class)->last());

        $this->assertStringEndsWith('Question du client : Vous avez le pagne bleu ?', Http::recorded()[0][0]->data()['messages'][0]['content']);
    }

    public function test_the_bot_stays_silent_when_the_owner_answers_during_the_wait(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Vous avez le pagne bleu ?', 'wamid.1');
        $this->travel(3)->seconds();
        $this->ownerReplies('Oui ma sœur, 7 500 🙏');

        $this->travel(5)->seconds();
        $this->runJob(Queue::pushed(ReplyToCustomer::class)->sole());

        Http::assertNothingSent();
        Queue::assertNotPushed(SendCustomerReply::class);
        $this->assertSame('answered_by_human', Message::where('direction', 'inbound')->value('status'));
    }

    public function test_nothing_is_sent_when_the_owner_answers_during_the_typing_delay(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);

        $this->receive('Vous avez le pagne bleu ?', 'wamid.1');
        $this->travel(8)->seconds();
        $this->runJob(Queue::pushed(ReplyToCustomer::class)->sole());
        Http::assertSentCount(1);

        $this->travel(2)->seconds();
        $this->ownerReplies('Oui, 7 500 🙏');
        $this->travel(2)->seconds();
        $this->runJob(Queue::pushed(SendCustomerReply::class)->sole());

        $this->assertSame([], FakeWhatsAppService::$sent);
        $this->assertSame(0, Message::where('direction', 'outbound')->where('sender_type', 'ai')->count());
        $this->assertSame('answered_by_human', Message::where('direction', 'inbound')->value('status'));
        $this->assertSame(0, (int) $this->business->fresh()->monthly_message_count);
    }

    public function test_an_escalated_burst_creates_one_escalation_with_all_the_messages(): void
    {
        Queue::fake([ReplyToCustomer::class, SendCustomerReply::class]);
        $this->claudeAnswer = "Je vérifie ça et je reviens vers vous très vite 😊\nJE_NE_SAIS_PAS";

        $this->receive('Vous livrez à Bobo ?', 'wamid.1');
        $this->travel(2)->seconds();
        $this->receive('Et à Koudougou ?', 'wamid.2');
        $this->travel(8)->seconds();
        $this->runJob(Queue::pushed(ReplyToCustomer::class)->last());
        $this->runJob(Queue::pushed(SendCustomerReply::class)->sole());

        $escalation = Escalation::sole();
        $this->assertSame("Vous livrez à Bobo ?\nEt à Koudougou ?", $escalation->customer_question);
        $this->assertSame(Message::where('whatsapp_message_id', 'wamid.2')->value('id'), $escalation->message_id);
        $this->assertSame(['escalated', 'escalated'], Message::where('direction', 'inbound')->orderBy('created_at')->pluck('status')->all());
        $this->assertSame(['Je vérifie ça et je reviens vers vous très vite'], FakeWhatsAppService::$sent);
    }

    public function test_without_a_fake_queue_the_reply_goes_out_in_the_same_flow(): void
    {
        $this->receive('Vous avez le pagne bleu ?', 'wamid.1');

        $this->assertSame([self::ANSWER], FakeWhatsAppService::$sent);
        $this->assertSame('answered_by_ai', Message::where('direction', 'inbound')->value('status'));
    }

    public function test_typing_delay_grows_with_the_answer_and_is_capped(): void
    {
        $pacing = new ReplyPacing;

        $this->assertSame(3.0, $pacing->typingSeconds(''));
        $this->assertSame(8.0, $pacing->typingSeconds(str_repeat('a', 200)));
        $this->assertSame(20.0, $pacing->typingSeconds(str_repeat('a', 2000)));

        config(['services.reply_pacing.typing_jitter_seconds' => 1.5]);
        foreach (range(1, 20) as $ignored) {
            $seconds = $pacing->typingSeconds(str_repeat('a', 200));
            $this->assertGreaterThanOrEqual(6.5, $seconds);
            $this->assertLessThanOrEqual(9.5, $seconds);
        }

        config(['services.reply_pacing.debounce_seconds' => 5, 'services.reply_pacing.max_wait_seconds' => 12]);
        $this->assertSame(5, $pacing->groupingDelay(now()));
        $this->assertSame(2, $pacing->groupingDelay(now()->subSeconds(10)));
        $this->assertSame(0, $pacing->groupingDelay(now()->subMinute()));
    }

    private function runJob(object $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    private function ownerReplies(string $text): void
    {
        Conversation::sole()->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'human',
            'content' => $text,
            'status' => 'answered_by_human',
        ]);
    }

    private function receive(string $text, string $messageId): void
    {
        $this->postJson('/api/webhook/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PNID-1'],
                        'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => '22670000001']],
                        'messages' => [['from' => '22670000001', 'id' => $messageId, 'type' => 'text', 'text' => ['body' => $text]]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }
}
