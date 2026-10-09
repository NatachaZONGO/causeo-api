<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIResponseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FixedContextAIResponseService;
use Tests\TestCase;

class ConversationSessionTest extends TestCase
{
    use CreatesConversationSchema;

    private const CONTEXT = 'Pagne wax : 7 500 FCFA, coloris bleu ou rouge. Sac en cuir : 12 000 FCFA. Livraison à Ouagadougou : 1 000 FCFA.';

    private const IN_PROGRESS_NOTE = 'cette conversation est déjà en cours';

    private Business $business;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test', 'services.anthropic.session_gap_hours' => 6]);
        $this->travelTo('2026-10-08 18:00:00');

        $this->createConversationSchema();

        $owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);
        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $businessId,
            'user_id' => $owner->id,
            'name' => 'Joyce',
            'modules' => '["orders","appointments"]',
        ]);
        $this->assignPlan($businessId);
        $this->business = Business::findOrFail($businessId);

        $this->conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $this->conversationId,
            'business_id' => $businessId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Avec plaisir !']],
        ])]);
    }

    public function test_messages_before_a_pause_longer_than_6_hours_are_not_sent(): void
    {
        $this->addMessage('2026-10-08 08:00:00', 'inbound', 'Je veux 2 pagnes wax bleus.');
        $this->addMessage('2026-10-08 08:01:00', 'outbound', "Récapitulatif : 2 × Pagne wax bleu, 15 000 FCFA. Je confirme la commande ?");
        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Vous avez des sacs ?');

        $this->service()->answer($this->business, 'Vous avez des sacs ?', $this->conversationId);

        $request = $this->sentRequest();
        $this->assertCount(1, $request['messages']);
        $this->assertSame('user', $request['messages'][0]['role']);
        $this->assertStringEndsWith('Question du client : Vous avez des sacs ?', $request['messages'][0]['content']);
        $this->assertStringNotContainsString('Pagne wax bleu, 15 000', json_encode($request['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(self::IN_PROGRESS_NOTE, $request['system']);
        $this->assertStringContainsString('Un récapitulatif resté sans réponse lors d\'un échange précédent est abandonné', $request['system']);
    }

    public function test_session_keeps_recent_messages_and_stops_at_the_first_long_pause(): void
    {
        $this->addMessage('2026-10-08 05:00:00', 'inbound', 'Quels sont vos horaires ?');
        $this->addMessage('2026-10-08 05:01:00', 'outbound', 'Nous sommes ouverts de 9h à 19h.');
        $this->addMessage('2026-10-08 13:00:00', 'inbound', 'Je cherche un pagne wax.');
        $this->addMessage('2026-10-08 13:01:00', 'outbound', 'Le pagne wax est à 7 500 FCFA, en bleu ou rouge.');
        $this->addMessage('2026-10-08 17:30:00', 'inbound', 'Et en rouge ?');
        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Je le prends.');

        $this->service()->answer($this->business, 'Je le prends.', $this->conversationId);

        $request = $this->sentRequest();
        $this->assertSame(['user', 'assistant', 'user'], array_column($request['messages'], 'role'));
        $this->assertSame('Je cherche un pagne wax.', $request['messages'][0]['content']);
        $this->assertSame('Le pagne wax est à 7 500 FCFA, en bleu ou rouge.', $request['messages'][1]['content']);
        $this->assertStringStartsWith('Et en rouge ?', $request['messages'][2]['content']);
        $this->assertStringEndsWith('Question du client : Je le prends.', $request['messages'][2]['content']);
        $this->assertStringNotContainsString('horaires', json_encode($request['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString(self::IN_PROGRESS_NOTE, $request['system']);
    }

    public function test_session_gap_is_configurable(): void
    {
        config(['services.anthropic.session_gap_hours' => 1]);
        $this->addMessage('2026-10-08 16:00:00', 'inbound', 'Vous livrez à Ouaga ?');
        $this->addMessage('2026-10-08 16:01:00', 'outbound', 'Oui, la livraison à Ouagadougou coûte 1 000 FCFA.');
        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Vous avez des sacs ?');

        $this->service()->answer($this->business, 'Vous avez des sacs ?', $this->conversationId);

        $this->assertCount(1, $this->sentRequest()['messages']);
    }

    public function test_history_is_capped_at_20_messages(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->addMessage(now()->subMinutes(31 - $i)->toDateTimeString(), $i % 2 === 0 ? 'inbound' : 'outbound', "Message {$i}");
        }
        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Question finale');

        $this->service()->answer($this->business, 'Question finale', $this->conversationId);

        $messages = $this->sentRequest()['messages'];
        $this->assertCount(21, $messages);
        $this->assertSame('Message 10', $messages[0]['content']);
        $this->assertStringEndsWith('Question du client : Question finale', $messages[20]['content']);
        $this->assertStringNotContainsString('"Message 9"', json_encode(array_column($messages, 'content')));
    }

    public function test_greeting_alone_after_a_pause_does_not_resume_the_old_topic(): void
    {
        $this->addMessage('2026-10-06 10:00:00', 'inbound', 'Je veux 2 pagnes wax bleus.');
        $this->addMessage('2026-10-06 10:01:00', 'outbound', 'Récapitulatif : 2 × Pagne wax bleu, 15 000 FCFA. Je confirme la commande ?');

        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Bonsoir');
        $greeting = $this->service()->answer($this->business, 'Bonsoir', $this->conversationId);

        $this->assertStringStartsWith('Bonsoir', $greeting['answer']);
        $this->assertStringNotContainsStringIgnoringCase('pagne', $greeting['answer']);
        $this->assertStringNotContainsStringIgnoringCase('commande', $greeting['answer']);

        // Un « oui » isolé ne confirme pas le récapitulatif abandonné.
        $this->addMessage('2026-10-08 18:00:30', 'inbound', 'oui');
        $yes = $this->service()->answer($this->business, 'oui', $this->conversationId);

        $this->assertStringNotContainsStringIgnoringCase('commande', $yes['answer']);
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_longer_opening_after_a_pause_gets_the_new_exchange_rule(): void
    {
        $this->addMessage('2026-10-06 10:00:00', 'inbound', 'Je veux 2 pagnes wax bleus.');
        $this->addMessage('2026-10-06 10:01:00', 'outbound', 'Récapitulatif : 2 × Pagne wax bleu, 15 000 FCFA. Je confirme la commande ?');
        $question = "Bonsoir, j'espère que vous allez bien, j'avais une petite question pour vous";
        $this->addMessage('2026-10-08 18:00:00', 'inbound', $question);

        $this->service()->answer($this->business, $question, $this->conversationId);

        $request = $this->sentRequest();
        $this->assertCount(1, $request['messages']);
        $this->assertStringContainsString(
            'Quand le client ouvre un nouvel échange par une salutation (il n\'y a pas d\'historique de messages), réponds à sa salutation et demande-lui comment tu peux l\'aider',
            $request['system'],
        );
        $this->assertStringNotContainsString('# Demandes en cours', $request['system']);
    }

    public function test_pending_order_and_appointment_are_summarized_instead_of_the_old_history(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $pending = $this->makeOrder('new', [
            'items' => [['name' => 'Pagne wax', 'options' => 'bleu', 'quantity' => 2, 'unit_price' => 7500]],
            'total_amount' => 15000,
        ]);
        $handled = $this->makeOrder('confirmed');
        $appointment = Appointment::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversationId,
            'customer_phone' => '22670000001',
            'customer_name' => 'Awa',
            'service' => 'Retouche',
            'requested_date' => '2026-10-10',
            'requested_time' => '15h',
            'status' => 'requested',
        ]);
        $this->addMessage('2026-10-06 10:00:00', 'inbound', 'Je veux 2 pagnes wax bleus.');
        $this->addMessage('2026-10-06 10:05:00', 'outbound', "Votre commande est enregistrée, référence {$pending->reference()}.");
        $this->travelTo('2026-10-08 18:00:00');

        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Où en est ma commande ?');
        $this->service()->answer($this->business, 'Où en est ma commande ?', $this->conversationId);

        $request = $this->sentRequest();
        $this->assertCount(1, $request['messages']);
        $this->assertStringContainsString("# Demandes en cours de ce client\nCes demandes ont été enregistrées et ne sont pas encore traitées. N'en parle pas de toi-même", $request['system']);
        $this->assertStringContainsString(
            "- Commande {$pending->reference()} du 06/10/2026 : 2 × Pagne wax (bleu), total 15 000 FCFA, livraison à Ouagadougou. Statut : enregistrée, pas encore traitée par l'entreprise.",
            $request['system'],
        );
        $this->assertStringContainsString(
            "- Demande de rendez-vous {$appointment->reference()} : Retouche, samedi 10 octobre à 15h. Statut : en attente de confirmation par l'entreprise.",
            $request['system'],
        );
        $this->assertStringNotContainsString($handled->reference(), $request['system']);
    }

    public function test_no_summary_when_nothing_is_pending(): void
    {
        $this->makeOrder('delivered');
        $this->addMessage('2026-10-08 18:00:00', 'inbound', 'Vous avez des sacs ?');

        $this->service()->answer($this->business, 'Vous avez des sacs ?', $this->conversationId);

        $this->assertStringNotContainsString('# Demandes en cours', $this->sentRequest()['system']);
    }

    private function addMessage(string $at, string $direction, string $content): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->conversationId,
            'direction' => $direction,
            'sender_type' => $direction === 'inbound' ? 'customer' : 'ai',
            'content' => $content,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
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
            'items' => [['name' => 'Sac en cuir', 'options' => null, 'quantity' => 1, 'unit_price' => 12000]],
            'total_amount' => 12000,
            'delivery_city' => 'Ouagadougou',
            'delivery_address' => 'Secteur 15',
            'payment_method' => 'Orange Money',
            'fulfillment_type' => 'delivery',
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentRequest(): array
    {
        Http::assertSentCount(1);

        return Http::recorded()[0][0]->data();
    }

    private function service(): AIResponseService
    {
        return new FixedContextAIResponseService(self::CONTEXT);
    }
}
