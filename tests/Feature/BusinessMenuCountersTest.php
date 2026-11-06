<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Compteurs du menu du dashboard, dans GET /businesses/{id} : conversations en attente du gérant
 * (les mêmes que le filtre « À traiter ») et commandes nouvelles.
 */
class BusinessMenuCountersTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        foreach (['documents', 'manager_replies'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->uuid('business_id');
                    $table->timestamps();
                });
            }
        }

        $this->owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        Sanctum::actingAs($this->owner);
    }

    public function test_pending_conversations_count_matches_the_inbox_filter_not_the_number_of_escalations(): void
    {
        $business = $this->business();
        // Une conversation avec deux demandes en attente ne compte qu'une fois.
        $this->conversationWith($business, ['pending', 'pending', 'answered']);
        $this->conversationWith($business, ['pending']);
        // Plus rien à traiter ici.
        $this->conversationWith($business, ['answered', 'resolved', 'expired']);
        $this->conversationWith($business, []);
        // Celle d'un autre gérant n'est pas comptée.
        $this->conversationWith($this->business(), ['pending']);

        $data = $this->counters($business);

        $this->assertSame(2, $data['pending_conversations_count']);
        $this->assertSame(3, $data['pending_escalations_count']);
        $this->assertSame(2, $this->getJson("/api/v1/businesses/{$business}/conversations?filter=pending")->assertOk()->json('total'));
    }

    public function test_pending_conversations_count_is_zero_without_pending_escalation(): void
    {
        $this->assertSame(0, $this->counters($this->business())['pending_conversations_count']);
    }

    public function test_new_orders_count_only_counts_new_orders_of_this_business(): void
    {
        $business = $this->business(['orders']);
        foreach (['new', 'new', 'confirmed', 'paid', 'delivered', 'cancelled'] as $status) {
            $this->order($business, $status);
        }
        $this->order($this->business(['orders']), 'new');

        $this->assertSame(2, $this->counters($business)['new_orders_count']);
    }

    public function test_new_orders_count_is_zero_when_the_module_is_active_without_new_order(): void
    {
        $this->assertSame(0, $this->counters($this->business(['orders']))['new_orders_count']);
    }

    public function test_new_orders_count_is_null_when_the_module_is_not_active(): void
    {
        $business = $this->business(['catalog']);
        $this->order($business, 'new');

        $data = $this->counters($business);

        $this->assertArrayHasKey('new_orders_count', $data);
        $this->assertNull($data['new_orders_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function counters(string $business): array
    {
        return $this->getJson("/api/v1/businesses/{$business}")->assertOk()->json('business');
    }

    /**
     * @param  list<string>  $modules
     */
    private function business(array $modules = []): string
    {
        $id = (string) Str::uuid();
        Business::forceCreate(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'Chez Awa', 'type' => 'boutique', 'modules' => $modules]);

        return $id;
    }

    /**
     * @param  list<string>  $statuses  une escalade par statut
     */
    private function conversationWith(string $business, array $statuses): void
    {
        $conversation = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversation, 'business_id' => $business, 'customer_phone' => '22670000000', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($statuses as $status) {
            $message = (string) Str::uuid();
            DB::table('messages')->insert([
                'id' => $message, 'conversation_id' => $conversation, 'direction' => 'inbound', 'sender_type' => 'customer',
                'content' => 'Bonjour', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('escalations')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'message_id' => $message, 'business_id' => $business,
                'customer_question' => 'Vous livrez ?', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function order(string $business, string $status): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $business, 'customer_phone' => '22670000000', 'items' => '[]',
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
