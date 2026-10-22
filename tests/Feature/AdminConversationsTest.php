<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class AdminConversationsTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        Schema::create('escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('business_id')->nullable();
            $table->text('customer_question');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
    }

    public function test_conversations_carry_the_business_name_and_pending_escalation_flag(): void
    {
        $joyce = $this->business('Joyce Boutique');
        $awa = $this->conversation($joyce, 'Awa', '2026-10-23 10:00:00');
        $this->escalation($awa, 'pending');
        $fatou = $this->conversation($this->business('Chez Fatou'), 'Fatou', '2026-10-23 09:00:00');
        $this->escalation($fatou, 'answered');

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]));
        $data = $this->getJson('/api/v1/admin/conversations')->assertOk()->json('data');

        $this->assertSame(['Awa', 'Fatou'], array_column($data, 'customer_name'));
        $this->assertSame(['Joyce Boutique', 'Chez Fatou'], array_column($data, 'business_name'));
        $this->assertSame([true, false], array_column($data, 'has_pending_escalation'));
        $this->assertSame(['Bonjour', 'Bonjour'], array_column(array_column($data, 'latest_message'), 'content'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_page(): void
    {
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->conversation($this->business('B1'), 'C1', '2026-10-23 10:00:00');
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/admin/conversations')->assertOk());

        for ($i = 2; $i <= 10; $i++) {
            $conversation = $this->conversation($this->business("B{$i}"), "C{$i}", '2026-10-23 10:00:00');
            $this->escalation($conversation, 'pending');
        }
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/admin/conversations')->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_conversations_are_reserved_to_admins(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/conversations')->assertForbidden();
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

    private function business(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => $name, 'modules' => '[]']);

        return $id;
    }

    private function conversation(string $businessId, string $customer, string $lastMessageAt): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $id, 'business_id' => $businessId, 'customer_phone' => '2267'.random_int(1000000, 9999999),
            'customer_name' => $customer, 'last_message_at' => $lastMessageAt,
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $id, 'direction' => 'inbound', 'sender_type' => 'customer',
            'content' => 'Bonjour', 'created_at' => $lastMessageAt, 'updated_at' => $lastMessageAt,
        ]);

        return $id;
    }

    private function escalation(string $conversationId, string $status): void
    {
        DB::table('escalations')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'customer_question' => 'Question', 'status' => $status,
        ]);
    }
}
