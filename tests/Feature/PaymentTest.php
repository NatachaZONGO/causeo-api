<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private User $admin;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00:00');
        $this->createConversationSchema();
        Storage::fake('supabase_documents');

        config([
            'services.payments.account_name' => 'Causeo',
            'services.payments.orange_money_number' => '70000000',
            'services.payments.moov_money_number' => '60000000',
        ]);

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
        $this->business = $this->makeBusiness($this->owner, 'BF');

        // Création du business : 30 jours de Pro offerts.
        app(BillingService::class)->startTrial($this->business, $this->owner);
    }

    public function test_owner_declares_a_payment_and_admins_are_notified(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->paymentsUrl(), [
            'plan' => 'starter',
            'method' => 'orange_money',
            'reference' => ' pp261009.1234.a56789 ',
            'months' => 3,
            'amount' => 10, // ignoré : le montant est calculé par le serveur
            'payer_phone' => '70 11 22 33',
        ])->assertCreated()
            ->assertJsonPath('payment.amount', 29700)
            ->assertJsonPath('payment.currency', 'XOF')
            ->assertJsonPath('payment.reference', 'PP261009.1234.A56789')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.has_proof', false)
            ->assertJsonPath('payment.plan.slug', 'starter');
        $this->assertArrayNotHasKey('proof_path', $response->json('payment'));

        $notification = Notification::query()->where('audience', Notification::ADMIN)->sole();
        $this->assertSame('billing', $notification->type);
        $this->assertSame('Paiement à valider : Boutique BF', $notification->title);
        $this->assertStringContainsString('29 700 FCFA par Orange Money pour la formule Starter (3 mois)', $notification->body);
        $this->assertSame(['kind' => 'payment_submitted', 'payment_id' => $response->json('payment.id')], $notification->data);

        // Le gérant ne voit pas les notifications des admins.
        $this->getJson("/api/v1/businesses/{$this->business->id}/notifications")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('unread_count', 0);
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();

        $this->getJson($this->paymentsUrl())->assertOk()->assertJsonPath('total', 1);

        // Tant qu'un admin n'a pas validé, rien ne change : toujours l'essai Pro.
        $this->assertSame('trialing', $this->business->fresh()->billingState()->status);
    }

    public function test_duplicate_reference_is_refused_whatever_the_case_or_spacing(): void
    {
        $this->declare(['reference' => 'PP261009.1234']);
        Sanctum::actingAs($this->owner);

        $this->postJson($this->paymentsUrl(), ['plan' => 'pro', 'method' => 'orange_money', 'reference' => ' pp 261009.1234 '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference' => 'Cette référence de transaction a déjà été déclarée.']);

        // Même référence d'un autre business : refusée aussi.
        $other = $this->makeBusiness($this->owner, 'BF');
        $this->postJson("/api/v1/businesses/{$other->id}/payments", ['plan' => 'pro', 'method' => 'orange_money', 'reference' => 'PP261009.1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');

        // L'unicité porte sur le moyen de paiement : la même référence chez Moov est acceptée.
        $this->postJson($this->paymentsUrl(), ['plan' => 'pro', 'method' => 'moov_money', 'reference' => 'PP261009.1234'])->assertCreated();

        $this->assertSame(2, Payment::count());
    }

    public function test_declaration_is_validated(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->paymentsUrl(), ['plan' => 'internal', 'method' => 'wave', 'months' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan', 'method', 'reference', 'months']);
        $this->postJson($this->paymentsUrl(), ['plan' => 'free', 'method' => 'orange_money', 'reference' => 'ABCD1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        // Un moyen sans numéro configuré n'est pas proposé.
        config(['services.payments.moov_money_number' => null]);
        $this->postJson($this->paymentsUrl(), ['plan' => 'pro', 'method' => 'moov_money', 'reference' => 'ABCD1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->assertSame(0, Payment::count());
    }

    public function test_payment_is_refused_outside_the_xof_zone(): void
    {
        $paris = $this->makeBusiness($this->owner, 'FR');
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/businesses/{$paris->id}/payments", ['plan' => 'pro', 'method' => 'orange_money', 'reference' => 'ABCD1234'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le paiement dans votre devise sera bientôt disponible.');

        $this->assertSame(0, Payment::count());
    }

    public function test_only_the_owner_can_declare_or_list_payments(): void
    {
        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'other@example.test', 'password' => 'x']));

        $this->postJson($this->paymentsUrl(), ['plan' => 'pro', 'method' => 'orange_money', 'reference' => 'ABCD1234'])->assertForbidden();
        $this->getJson($this->paymentsUrl())->assertForbidden();
    }

    public function test_approval_during_the_trial_starts_a_paid_period_once(): void
    {
        $payment = $this->declare(['plan' => 'starter', 'months' => 2]);
        $this->travel(5)->days();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")
            ->assertOk()
            ->assertJsonPath('payment.status', 'approved');

        $payment->refresh();
        $state = $this->business->fresh()->billingState();
        $this->assertSame('active', $state->status);
        $this->assertSame('starter', $state->plan->slug);
        $this->assertSame('starter', $this->business->fresh()->getRawOriginal('plan'));
        // L'essai s'arrête : la période payée commence à la validation, pour 2 × 30 jours.
        $this->assertTrue($payment->period_start->equalTo(now()));
        $this->assertTrue($payment->period_end->equalTo(now()->addDays(60)));
        $this->assertTrue($state->endsAt->equalTo($payment->period_end));
        $this->assertSame($this->admin->id, $payment->reviewed_by);
        $this->assertSame($state->subscription->id, $payment->subscription_id);
        $this->assertNull(Subscription::sole()->trial_ends_at);

        $notification = $this->business->notifications()->sole();
        $this->assertSame('Paiement validé', $notification->title);
        $this->assertSame('Votre paiement de 19 800 FCFA est validé : formule Starter active jusqu\'au 13/12/2026.', $notification->body);
        $this->assertSame(['kind' => 'payment_approved', 'payment_id' => $payment->id], $notification->data);

        // Une deuxième validation est refusée et ne prolonge rien.
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertStatus(409);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'Doublon'])->assertStatus(409);
        $this->assertTrue($this->business->fresh()->billingState()->endsAt->equalTo($payment->period_end));
        $this->assertSame(1, $this->business->notifications()->count());
    }

    public function test_paying_the_same_plan_extends_from_the_end_of_the_period(): void
    {
        $first = $this->declare(['plan' => 'pro', 'reference' => 'FIRST001']);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/payments/{$first->id}/approve")->assertOk();
        $end = $first->fresh()->period_end;

        // Renouvellement anticipé : la nouvelle période suit la précédente.
        $this->travel(20)->days();
        $second = $this->declare(['plan' => 'pro', 'reference' => 'SECOND02']);
        $this->postJson("/api/v1/admin/payments/{$second->id}/approve")->assertOk();
        $second->refresh();
        $this->assertTrue($second->period_start->equalTo($end));
        $this->assertTrue($second->period_end->equalTo($end->copy()->addDays(30)));

        // Pendant la grâce : toujours prolongé depuis l'échéance, rien n'est perdu.
        $this->travelTo($second->period_end->copy()->addDay());
        $this->assertSame('grace', $this->business->fresh()->billingState()->status);
        $third = $this->declare(['plan' => 'pro', 'reference' => 'THIRD003']);
        $this->postJson("/api/v1/admin/payments/{$third->id}/approve")->assertOk();
        $this->assertTrue($third->fresh()->period_start->equalTo($second->period_end));
        $this->assertSame('active', $this->business->fresh()->billingState()->status);

        // Changement de formule : une nouvelle période commence maintenant.
        $fourth = $this->declare(['plan' => 'business', 'reference' => 'FOURTH04']);
        $this->postJson("/api/v1/admin/payments/{$fourth->id}/approve")->assertOk();
        $this->assertTrue($fourth->fresh()->period_start->equalTo(now()));
        $this->assertSame('business', $this->business->fresh()->billingState()->plan->slug);
    }

    public function test_payment_after_falling_back_to_free_starts_now(): void
    {
        $first = $this->declare(['plan' => 'pro', 'reference' => 'FIRST001']);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/payments/{$first->id}/approve")->assertOk();

        // Échéance + 3 jours de grâce dépassés : retour en Gratuit.
        $this->travelTo($first->fresh()->period_end->copy()->addDays(4));
        $this->assertSame('free', $this->business->fresh()->billingState()->status);

        $second = $this->declare(['plan' => 'pro', 'reference' => 'SECOND02']);
        $this->postJson("/api/v1/admin/payments/{$second->id}/approve")->assertOk();
        $this->assertTrue($second->fresh()->period_start->equalTo(now()));
        $this->assertSame('active', $this->business->fresh()->billingState()->status);
    }

    public function test_admin_rejects_with_a_reason(): void
    {
        $payment = $this->declare(['reference' => 'WRONG123']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'Transaction introuvable.'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'rejected')
            ->assertJsonPath('payment.rejection_reason', 'Transaction introuvable.');

        // L'abonnement ne change pas.
        $this->assertSame('trialing', $this->business->fresh()->billingState()->status);

        $notification = $this->business->notifications()->sole();
        $this->assertSame('Paiement refusé', $notification->title);
        $this->assertStringContainsString('(référence WRONG123) a été refusé : Transaction introuvable.', $notification->body);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertStatus(409);
    }

    public function test_admin_lists_payments_and_downloads_the_proof(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson($this->paymentsUrl(), [
            'plan' => 'pro',
            'method' => 'moov_money',
            'reference' => 'MOOV0001',
            'proof' => UploadedFile::fake()->image('capture.png'),
        ])->assertCreated()->assertJsonPath('payment.has_proof', true);
        $payment = Payment::sole();
        Storage::disk('supabase_documents')->assertExists($payment->proof_path);
        $this->assertStringStartsWith("payments/{$this->business->id}/", $payment->proof_path);

        $this->postJson($this->paymentsUrl(), [
            'plan' => 'pro', 'method' => 'orange_money', 'reference' => 'OM000002',
            'proof' => UploadedFile::fake()->create('script.exe', 10),
        ])->assertUnprocessable()->assertJsonValidationErrors('proof');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/payments?status=pending')
            ->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('data.0.reference', 'MOOV0001')
            ->assertJsonPath('data.0.business.name', 'Boutique BF')
            ->assertJsonPath('data.0.business.user.email', 'owner@example.test');
        $this->getJson('/api/v1/admin/payments?status=approved')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/payments?status=unknown')->assertUnprocessable();

        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertOk()->assertDownload();
    }

    public function test_admin_notifications(): void
    {
        $this->declare(['reference' => 'AAAA0001']);
        $this->declare(['reference' => 'BBBB0002']);
        $ownerNotification = Notification::create([
            'business_id' => $this->business->id, 'type' => 'billing', 'title' => 'Pour le gérant', 'body' => '...',
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.business.name', 'Boutique BF');

        $first = Notification::query()->where('audience', Notification::ADMIN)->first();
        $this->postJson("/api/v1/admin/notifications/{$first->id}/read")->assertOk();
        $this->getJson('/api/v1/admin/notifications')->assertJsonPath('unread_count', 1);
        $this->postJson("/api/v1/admin/notifications/{$ownerNotification->id}/read")->assertNotFound();

        $this->postJson('/api/v1/admin/notifications/read-all')->assertOk()->assertJsonPath('updated', 1);
        $this->assertNull($ownerNotification->fresh()->read_at);
    }

    public function test_admin_routes_are_reserved_to_admins(): void
    {
        $payment = $this->declare();
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertForbidden();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'Non'])->assertForbidden();
        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->postJson('/api/v1/admin/notifications/read-all')->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /**
     * Déclarer un paiement en tant que gérant.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function declare(array $overrides = []): Payment
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->paymentsUrl(), [
            'plan' => 'pro',
            'method' => 'orange_money',
            'reference' => 'REF'.Str::upper(Str::random(8)),
            ...$overrides,
        ])->assertCreated()->json('payment.id');

        Sanctum::actingAs($this->admin);

        return Payment::findOrFail($id);
    }

    private function paymentsUrl(): string
    {
        return "/api/v1/businesses/{$this->business->id}/payments";
    }

    private function makeBusiness(User $owner, string $country): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => "Boutique {$country}", 'country' => $country, 'modules' => '[]']);

        return Business::findOrFail($id);
    }
}
