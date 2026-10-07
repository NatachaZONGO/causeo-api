<?php

namespace Tests\Feature;

use App\Models\Escalation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    private User $owner;

    private string $businessId;

    private string $conversationA;

    private string $conversationB;

    protected function setUp(): void
    {
        parent::setUp();

        // Les migrations complètes dépendent de pgvector : on crée ici un schéma
        // minimal sur SQLite, puis la vraie migration de la table notifications.
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
        (require base_path('database/migrations/2026_10_07_100000_create_notifications_table.php'))->up();

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
        $this->businessId = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $this->businessId, 'user_id' => $this->owner->id, 'name' => 'Joyce']);

        $this->conversationA = (string) Str::uuid();
        $this->conversationB = (string) Str::uuid();
        DB::table('conversations')->insert([
            ['id' => $this->conversationA, 'business_id' => $this->businessId, 'customer_phone' => '22670000001', 'customer_name' => 'Awa'],
            ['id' => $this->conversationB, 'business_id' => $this->businessId, 'customer_phone' => '22670000002', 'customer_name' => null],
        ]);
    }

    public function test_escalation_creates_a_notification_with_the_customer_question(): void
    {
        $escalation = $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');

        $notification = Notification::sole();
        $this->assertSame($this->businessId, $notification->business_id);
        $this->assertSame('escalation', $notification->type);
        $this->assertSame('Question de Awa à traiter', $notification->title);
        $this->assertSame('Livrez-vous à Koudougou ?', $notification->body);
        $this->assertSame([
            'conversation_id' => $this->conversationA,
            'escalation_id' => $escalation->id,
        ], $notification->data);
        $this->assertNull($notification->read_at);
    }

    public function test_title_falls_back_to_phone_and_business_to_conversation(): void
    {
        $this->escalate($this->conversationB, 'Prix du pagne ?', withBusiness: false);

        $notification = Notification::sole();
        $this->assertSame($this->businessId, $notification->business_id);
        $this->assertSame('Question de 22670000002 à traiter', $notification->title);
    }

    public function test_unread_notification_for_same_conversation_is_updated_instead_of_duplicated(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        $this->travel(5)->minutes();
        $second = $this->escalate($this->conversationA, 'Et à Kaya ?');
        $this->escalate($this->conversationB, 'Prix du pagne ?');

        $this->assertSame(2, Notification::count());

        $grouped = Notification::where('data->conversation_id', $this->conversationA)->sole();
        $this->assertSame('Et à Kaya ?', $grouped->body);
        $this->assertSame($second->id, $grouped->data['escalation_id']);
        $this->assertTrue($grouped->updated_at->gt($grouped->created_at));
    }

    public function test_new_notification_is_created_once_the_previous_one_is_read(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        Notification::query()->update(['read_at' => now()]);

        $this->escalate($this->conversationA, 'Et à Banfora ?');

        $this->assertSame(2, Notification::count());
        $this->assertSame(1, Notification::whereNull('read_at')->count());
    }

    public function test_index_lists_latest_first_with_unread_count(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        $this->travel(1)->minutes();
        $this->escalate($this->conversationB, 'Prix du pagne ?');
        $this->travel(1)->minutes();
        $this->escalate($this->conversationA, 'Et à Kaya ?');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->businessId}/notifications")
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('data.0.body', 'Et à Kaya ?')
            ->assertJsonPath('data.1.body', 'Prix du pagne ?');
    }

    public function test_read_marks_a_single_notification_as_read(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        $this->escalate($this->conversationB, 'Prix du pagne ?');
        $notification = Notification::where('data->conversation_id', $this->conversationA)->sole();

        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson("/api/v1/businesses/{$this->businessId}/notifications")->assertJsonPath('unread_count', 1);
    }

    public function test_read_all_marks_every_notification_as_read(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        $this->escalate($this->conversationB, 'Prix du pagne ?');

        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/businesses/{$this->businessId}/notifications/read-all")
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->getJson("/api/v1/businesses/{$this->businessId}/notifications")->assertJsonPath('unread_count', 0);
    }

    public function test_other_users_cannot_access_notifications(): void
    {
        $this->escalate($this->conversationA, 'Livrez-vous à Koudougou ?');
        $notification = Notification::sole();

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->getJson("/api/v1/businesses/{$this->businessId}/notifications")->assertForbidden();
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertForbidden();
        $this->postJson("/api/v1/businesses/{$this->businessId}/notifications/read-all")->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    private function escalate(string $conversationId, string $question, bool $withBusiness = true): Escalation
    {
        return Escalation::create(array_filter([
            'conversation_id' => $conversationId,
            'business_id' => $withBusiness ? $this->businessId : null,
            'customer_question' => $question,
            'status' => 'pending',
        ]));
    }
}
