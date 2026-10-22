<?php

namespace Tests\Feature;

use App\Models\AdminAccessLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Boîte de réception du dashboard : non lus, filtres, recherche, pagination des
 * messages et lecture par un admin.
 */
class ConversationInboxTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private string $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-24 12:00:00');
        $this->createConversationSchema();
        Schema::create('escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('message_id')->nullable();
            $table->uuid('business_id')->nullable();
            $table->text('customer_question');
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_23_110000_create_admin_access_logs_table.php'))->up();
        (require base_path('database/migrations/2026_10_24_110000_add_conversation_id_to_admin_access_logs.php'))->up();

        $this->owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $this->business = $this->newBusiness($this->owner);
    }

    // --- Liste ------------------------------------------------------------------------------------

    public function test_each_conversation_carries_unread_count_orders_latest_message_and_pending_escalations(): void
    {
        $awa = $this->conversation('Awa', '+226 70 11 22 33', lastReadAt: '2026-10-24 09:00:00');
        $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 08:00:00');
        $this->message($awa, 'inbound', 'Vous êtes là ?', '2026-10-24 10:00:00');
        $this->message($awa, 'outbound', 'Oui, je regarde.', '2026-10-24 10:05:00', 'ai');
        $this->message($awa, 'inbound', 'Merci', '2026-10-24 10:10:00');
        $this->escalation($awa, 'Question ancienne', 'pending', '2026-10-24 09:30:00');
        $this->escalation($awa, 'Livrez-vous à Koudougou ?', 'pending', '2026-10-24 10:10:00');
        $this->escalation($awa, 'Réglée', 'answered', '2026-10-24 07:00:00');
        $this->order($awa);

        $fatou = $this->conversation('Fatou', '22676543210', lastReadAt: '2026-10-24 11:00:00');
        $this->message($fatou, 'inbound', 'Bonsoir', '2026-10-24 08:30:00');

        Sanctum::actingAs($this->owner);
        $data = $this->getJson("/api/v1/businesses/{$this->business}/conversations")->assertOk()->json('data');

        $this->assertSame(['Awa', 'Fatou'], array_column($data, 'customer_name'));
        $this->assertSame([2, 0], array_column($data, 'unread_count'));
        $this->assertSame([true, false], array_map(fn ($c) => (bool) $c['has_orders'], $data));

        $latest = $data[0]['latest_message'];
        $this->assertSame(['inbound', 'Merci'], [$latest['direction'], $latest['content']]);
        $this->assertSame(
            ['Livrez-vous à Koudougou ?', 'Question ancienne'],
            array_column($data[0]['escalations'], 'customer_question'),
        );
    }

    public function test_filters_keep_unread_pending_or_ordering_conversations(): void
    {
        $unread = $this->conversation('Non lue', '22670000001', lastReadAt: '2026-10-24 09:00:00');
        $this->message($unread, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $pending = $this->conversation('À traiter', '22670000002', lastReadAt: '2026-10-24 11:00:00');
        $this->message($pending, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $this->escalation($pending, 'Question', 'pending', '2026-10-24 10:00:00');
        $ordered = $this->conversation('Commande', '22670000003', lastReadAt: '2026-10-24 11:00:00');
        $this->message($ordered, 'outbound', 'Commande enregistrée', '2026-10-24 10:00:00', 'ai');
        $this->order($ordered);

        Sanctum::actingAs($this->owner);
        $names = fn (string $filter) => array_column(
            $this->getJson("/api/v1/businesses/{$this->business}/conversations?filter={$filter}")->assertOk()->json('data'),
            'customer_name',
        );

        $this->assertSame(['Non lue'], $names('unread'));
        $this->assertSame(['À traiter'], $names('pending'));
        $this->assertSame(['Commande'], $names('orders'));
        $this->assertCount(3, $names('all'));
        $this->getJson("/api/v1/businesses/{$this->business}/conversations?filter=lues")->assertUnprocessable();
    }

    public function test_search_matches_the_name_the_number_or_the_text_of_a_message(): void
    {
        $joyce = $this->conversation('Joyce🌹', '+226 70 11 22 33');
        $this->message($joyce, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $paul = $this->conversation('Paul', '22676543210');
        $this->message($paul, 'inbound', 'Avez-vous des PAGNES wax ?', '2026-10-24 09:00:00');

        Sanctum::actingAs($this->owner);
        $search = fn (string $q) => array_column(
            $this->getJson("/api/v1/businesses/{$this->business}/conversations?q=".urlencode($q))->assertOk()->json('data'),
            'customer_name',
        );

        $this->assertSame(['Joyce🌹'], $search('joyce'));
        $this->assertSame(['Paul'], $search('7654'));
        $this->assertSame(['Paul'], $search('pagnes'));
        $this->assertSame([], $search('100%'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_page(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/businesses/{$this->business}/conversations";

        $first = $this->conversation('C1', '22670000001');
        $this->message($first, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $few = $this->countQueries(fn () => $this->getJson($url)->assertOk());

        for ($i = 2; $i <= 10; $i++) {
            $conversation = $this->conversation("C{$i}", '2267000000'.$i);
            $this->message($conversation, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
            $this->escalation($conversation, 'Question', 'pending', '2026-10-24 10:00:00');
            $this->order($conversation);
        }
        $many = $this->countQueries(fn () => $this->getJson($url)->assertOk());

        $this->assertSame($few, $many);
    }

    // --- Lecture ----------------------------------------------------------------------------------

    public function test_reading_a_conversation_clears_its_unread_messages(): void
    {
        $awa = $this->conversation('Awa', '22670112233', lastReadAt: '2026-10-24 09:00:00');
        $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 10:00:00');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/conversations/{$awa}/read")->assertOk()->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->getJson("/api/v1/businesses/{$this->business}/conversations")->json('data.0.unread_count'));

        $this->travel(1)->minutes();
        $this->message($awa, 'inbound', 'Encore moi', now()->toDateTimeString());
        $this->assertSame(1, $this->getJson("/api/v1/businesses/{$this->business}/conversations")->json('data.0.unread_count'));
    }

    public function test_only_the_owner_marks_a_conversation_as_read(): void
    {
        $awa = $this->conversation('Awa', '22670112233');
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/conversations/{$awa}/read")->assertForbidden();

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->postJson("/api/v1/conversations/{$awa}/read")->assertForbidden();
    }

    // --- Messages ---------------------------------------------------------------------------------

    public function test_messages_come_by_pages_of_the_most_recent_then_older_ones(): void
    {
        $awa = $this->conversation('Awa', '22670112233');
        for ($i = 1; $i <= 120; $i++) {
            $this->message($awa, 'inbound', "Message {$i}", now()->subMinutes(121 - $i)->toDateTimeString());
        }

        Sanctum::actingAs($this->owner);
        $page = $this->getJson("/api/v1/conversations/{$awa}")->assertOk()->json('conversation');

        $this->assertCount(50, $page['messages']);
        $this->assertSame('Message 71', $page['messages'][0]['content']);
        $this->assertSame('Message 120', $page['messages'][49]['content']);
        $this->assertTrue($page['has_older_messages']);

        $older = $this->getJson("/api/v1/conversations/{$awa}?before={$page['messages'][0]['id']}&limit=60")->json('conversation');
        $this->assertCount(60, $older['messages']);
        $this->assertSame('Message 11', $older['messages'][0]['content']);
        $this->assertSame('Message 70', $older['messages'][59]['content']);
        $this->assertTrue($older['has_older_messages']);

        $oldest = $this->getJson("/api/v1/conversations/{$awa}?before={$older['messages'][0]['id']}")->json('conversation');
        $this->assertSame('Message 1', $oldest['messages'][0]['content']);
        $this->assertCount(10, $oldest['messages']);
        $this->assertFalse($oldest['has_older_messages']);

        $this->getJson("/api/v1/conversations/{$awa}?before=".Str::uuid())->assertNotFound();
    }

    public function test_messages_sent_in_the_same_second_are_neither_lost_nor_repeated(): void
    {
        $awa = $this->conversation('Awa', '22670112233');
        for ($i = 1; $i <= 5; $i++) {
            $this->message($awa, 'inbound', "Message {$i}", '2026-10-24 10:00:00');
        }

        Sanctum::actingAs($this->owner);
        $seen = [];
        $before = null;
        do {
            $page = $this->getJson("/api/v1/conversations/{$awa}?limit=2".($before ? "&before={$before}" : ''))->json('conversation');
            $seen = [...array_column($page['messages'], 'id'), ...$seen];
            $before = $page['messages'][0]['id'] ?? null;
        } while ($page['has_older_messages']);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_an_admin_reads_a_conversation_and_the_access_is_logged_once_per_period(): void
    {
        $awa = $this->conversation('Awa', '22670112233');
        $this->message($awa, 'inbound', 'Bonjour', '2026-10-24 10:00:00');
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/conversations/{$awa}")->assertOk()->assertJsonPath('conversation.messages.0.content', 'Bonjour');
        $this->getJson("/api/v1/conversations/{$awa}")->assertOk();

        $logs = AdminAccessLog::all();
        $this->assertCount(1, $logs);
        $this->assertSame([AdminAccessLog::CONVERSATION_VIEWED, $awa, $this->business, $admin->id], [
            $logs[0]->action, $logs[0]->conversation_id, $logs[0]->business_id, $logs[0]->admin_id,
        ]);

        $this->travel(31)->minutes();
        $this->getJson("/api/v1/conversations/{$awa}")->assertOk();
        $this->assertSame(2, AdminAccessLog::count());

        // Le gérant ouvre sa conversation : rien n'est journalisé.
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/conversations/{$awa}")->assertOk();
        $this->assertSame(2, AdminAccessLog::count());

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->getJson("/api/v1/conversations/{$awa}")->assertForbidden();
    }

    public function test_the_admin_list_has_the_same_search_and_filters(): void
    {
        $awa = $this->conversation('Awa', '22670112233');
        $this->message($awa, 'inbound', 'Avez-vous des pagnes ?', '2026-10-24 10:00:00');
        $this->escalation($awa, 'Question', 'pending', '2026-10-24 10:00:00');
        $fatou = $this->conversation('Fatou', '22676543210');
        $this->message($fatou, 'inbound', 'Bonsoir', '2026-10-24 09:00:00');

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]));

        $this->assertSame(['Awa'], array_column($this->getJson('/api/v1/admin/conversations?filter=pending')->json('data'), 'customer_name'));
        $this->assertSame(['Awa'], array_column($this->getJson('/api/v1/admin/conversations?q=pagnes')->json('data'), 'customer_name'));
        $this->assertSame(['Fatou'], array_column($this->getJson('/api/v1/admin/conversations?search=fatou')->json('data'), 'customer_name'));
    }

    // --- Données de test --------------------------------------------------------------------------

    private function newBusiness(User $owner): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => 'Joyce Boutique', 'modules' => '["orders"]']);

        return $id;
    }

    private function conversation(string $name, string $phone, ?string $lastReadAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $id, 'business_id' => $this->business, 'customer_phone' => $phone, 'customer_name' => $name,
            'last_read_at' => $lastReadAt, 'last_message_at' => null,
        ]);

        return $id;
    }

    private function message(string $conversation, string $direction, string $content, string $at, string $sender = 'customer'): string
    {
        $id = (string) Str::uuid();
        DB::table('messages')->insert([
            'id' => $id, 'conversation_id' => $conversation, 'direction' => $direction, 'sender_type' => $sender,
            'content' => $content, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('conversations')->where('id', $conversation)
            ->where(fn ($q) => $q->whereNull('last_message_at')->orWhere('last_message_at', '<', $at))
            ->update(['last_message_at' => $at]);

        return $id;
    }

    private function escalation(string $conversation, string $question, string $status, string $at): void
    {
        DB::table('escalations')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'business_id' => $this->business,
            'customer_question' => $question, 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function order(string $conversation): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $this->business, 'conversation_id' => $conversation,
            'customer_phone' => '22670000000', 'items' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
