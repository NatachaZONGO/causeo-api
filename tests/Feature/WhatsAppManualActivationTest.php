<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Le mode « Pro » (activation manuelle par un admin) est abandonné : seule la
 * Coexistence (Embedded Signup) reste. Les demandes déjà enregistrées sont conservées.
 */
class WhatsAppManualActivationTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'owner@example.test', 'password' => 'x']);
        $businessId = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $businessId,
            'user_id' => $this->owner->id,
            'name' => 'Joyce',
            'modules' => '[]',
            'whatsapp_display_name' => 'Joyce Boutique',
        ]);
        $this->business = Business::findOrFail($businessId);
    }

    public function test_activation_request_is_gone_and_leaves_the_pending_request_untouched(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp/request", [
            'phone' => '+22670999999',
            'display_name' => 'Autre nom',
        ])
            ->assertStatus(410)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Coexistence'));

        $business = $this->business->fresh();
        $this->assertSame('Joyce Boutique', $business->whatsapp_display_name);
    }

    public function test_activation_request_still_checks_ownership(): void
    {
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp/request")->assertForbidden();
    }

    public function test_status_no_longer_reports_a_pending_request(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/businesses/{$this->business->id}/whatsapp/status")
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('pending', false)
            ->assertJsonPath('requested_at', null);
    }

    public function test_admin_manual_configuration_is_gone_and_changes_nothing(): void
    {
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/businesses/{$this->business->id}/whatsapp", [
            'waba_id' => 'WABA-1',
            'phone_number_id' => 'PNID-1',
        ])->assertStatus(410);

        $business = $this->business->fresh();
        $this->assertNull($business->whatsapp_phone_number_id);
        $this->assertNull($business->whatsapp_waba_id);
        $this->assertFalse((bool) $business->whatsapp_verified);
    }
}
