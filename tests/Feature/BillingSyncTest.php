<?php

namespace Tests\Feature;

use App\Mail\OwnerNotificationMail;
use App\Models\Business;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class BillingSyncTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // Vendredi 9 octobre 2026, 10 h à Ouagadougou (UTC).
        $this->travelTo('2026-10-09 10:00:00');
        $this->createConversationSchema();
        Mail::fake();
        config(['app.frontend_url' => 'https://dashboard.example.test']);

        $this->owner = User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']);
        $this->business = $this->makeBusiness('Boutique Awa');
    }

    public function test_trial_reminders_are_sent_once_with_catch_up_then_the_business_falls_back_to_free(): void
    {
        // Essai Pro jusqu'au 8 novembre, 10 h.
        app(BillingService::class)->startTrial($this->business, $this->owner);

        $this->sync();
        $this->assertSame([], $this->reminderKinds());

        // J-7, deux passages : un seul rappel.
        $this->travelTo('2026-11-01 09:00:00');
        $this->sync();
        $this->sync();
        $this->assertSame(['reminder_7'], $this->reminderKinds());
        $notification = $this->business->notifications()->sole();
        $this->assertSame('billing', $notification->type);
        $this->assertSame('Votre essai Pro se termine dans 7 jours', $notification->title);
        $this->assertStringContainsString('prend fin le 08/11/2026 à 10h00', $notification->body);
        $this->assertStringContainsString('formule Gratuit : 10 réponses automatiques par jour', $notification->body);
        $this->assertSame('reminder_7', $notification->data['kind']);
        Mail::assertSent(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->hasTo('owner@example.test')
            && $mail->title === 'Votre essai Pro se termine dans 7 jours'
            && $mail->actionUrl === 'https://dashboard.example.test/dashboard/billing');

        // J-5 : rien de nouveau.
        $this->travelTo('2026-11-03 09:00:00');
        $this->sync();
        $this->assertSame(['reminder_7'], $this->reminderKinds());

        // J-3 manqué (serveur arrêté) : rattrapé à J-2, avec le bon nombre de jours.
        $this->travelTo('2026-11-06 09:00:00');
        $this->sync();
        $this->assertSame(['reminder_7', 'reminder_3'], $this->reminderKinds());
        $this->assertSame('Votre essai Pro se termine dans 2 jours', $this->latestNotification()->title);

        // J-1 la nuit : on attend le matin.
        $this->travelTo('2026-11-07 03:00:00');
        $this->sync();
        $this->assertCount(2, $this->reminderKinds());
        $this->travelTo('2026-11-07 08:00:00');
        $this->sync();
        $this->assertSame('Votre essai Pro se termine demain', $this->latestNotification()->title);

        $this->travelTo('2026-11-08 08:30:00');
        $this->sync();
        $this->assertSame('Votre essai Pro se termine aujourd\'hui', $this->latestNotification()->title);
        $this->assertSame('trialing', Subscription::sole()->status);

        // Fin de l'essai : statut et formule enregistrés, gérant prévenu une fois.
        $this->travelTo('2026-11-08 11:00:00');
        $this->sync();
        $this->sync();

        $subscription = Subscription::sole();
        $this->assertSame('expired', $subscription->status);
        $this->assertTrue($subscription->ended_at->equalTo('2026-11-08 10:00:00'));
        $this->assertSame('free', $this->business->fresh()->getRawOriginal('plan'));
        $this->assertSame(['reminder_7', 'reminder_3', 'reminder_1', 'reminder_0', 'downgraded'], $this->reminderKinds());
        $this->assertSame('Votre assistant est passé en formule Gratuit', $this->latestNotification()->title);
        $this->assertStringContainsString('Votre essai de la formule Pro est terminé.', $this->latestNotification()->body);
        $this->assertSame(5, $this->business->notifications()->count());
        Mail::assertSentCount(5);
    }

    public function test_paid_plan_goes_through_grace_then_free(): void
    {
        // Starter payée jusqu'au 8 novembre, 10 h.
        app(BillingService::class)->assignPlan($this->business, Plan::bySlug(Plan::STARTER));

        $this->travelTo('2026-11-05 10:00:00');
        $this->sync();
        $this->assertSame('Votre formule Starter expire dans 3 jours', $this->latestNotification()->title);
        $this->assertStringContainsString('3 jours de grâce', $this->latestNotification()->body);

        // Échéance passée : grâce enregistrée, un seul message (le J0 manqué n'est pas rattrapé).
        $this->travelTo('2026-11-08 12:00:00');
        $this->sync();
        $this->sync();
        $this->assertSame('grace', Subscription::sole()->status);
        $this->assertSame('starter', $this->business->fresh()->getRawOriginal('plan'));
        $this->assertSame(['reminder_3', 'grace'], $this->reminderKinds());
        $this->assertSame('Votre formule Starter a expiré', $this->latestNotification()->title);
        $this->assertStringContainsString('jusqu\'au 11/11/2026 à 10h00', $this->latestNotification()->body);

        // Fin de la grâce : passage en Gratuit enregistré et annoncé.
        $this->travelTo('2026-11-11 10:30:00');
        $this->sync();

        $subscription = Subscription::sole();
        $this->assertSame('expired', $subscription->status);
        $this->assertTrue($subscription->ended_at->equalTo('2026-11-11 10:00:00'));
        $this->assertSame('free', $this->business->fresh()->getRawOriginal('plan'));
        $this->assertSame(['reminder_3', 'grace', 'downgraded'], $this->reminderKinds());
        $this->assertStringContainsString('Votre formule Starter n\'a pas été renouvelée.', $this->latestNotification()->body);
    }

    public function test_downgrade_is_announced_even_at_night(): void
    {
        app(BillingService::class)->startTrial($this->business, $this->owner);
        DB::table('subscriptions')->update(['trial_ends_at' => '2026-10-10 01:00:00']);

        $this->travelTo('2026-10-10 02:00:00');
        $this->sync();

        $this->assertSame('expired', Subscription::sole()->status);
        $this->assertSame(['downgraded'], $this->reminderKinds());
    }

    public function test_internal_plan_gets_no_reminder(): void
    {
        $this->assignPlan($this->business->id);

        $this->travelTo('2027-10-09 10:00:00');
        $this->sync();

        $this->assertSame('active', Subscription::sole()->status);
        $this->assertSame([], $this->reminderKinds());
        Mail::assertNothingSent();
    }

    public function test_status_is_set_back_to_active_after_a_payment_during_grace(): void
    {
        app(BillingService::class)->assignPlan($this->business, Plan::bySlug(Plan::STARTER));
        $this->travelTo('2026-11-08 12:00:00');
        $this->sync();
        $this->assertSame('grace', Subscription::sole()->status);

        app(BillingService::class)->applyPayment($this->business, Plan::bySlug(Plan::STARTER), 1);
        $this->sync();

        $this->assertSame('active', Subscription::sole()->status);
        $this->assertTrue(Subscription::sole()->current_period_end->equalTo('2026-12-08 10:00:00'));
    }

    public function test_weekly_report_sums_up_the_previous_week_once(): void
    {
        $this->assignPlan($this->business->id);
        $quiet = $this->makeBusiness('Boutique calme');
        $this->assignPlan($quiet->id);

        // Semaine du lundi 12 au dimanche 18 octobre.
        $conversation = $this->conversation($this->business);
        $this->messages($conversation, 45, '2026-10-13 15:00:00');
        $this->messages($conversation, 2, '2026-10-13 15:00:00', canned: true); // salutations : non comptées
        $this->messages($conversation, 3, '2026-10-19 07:00:00'); // semaine suivante
        $this->order($conversation, 15000, 'confirmed', '2026-10-14 10:00:00');
        $this->order($conversation, 7500, 'cancelled', '2026-10-14 11:00:00');
        $this->appointment($conversation, 5000, 'confirmed', '2026-10-15 10:00:00');
        $this->appointment($conversation, 9000, 'declined', '2026-10-15 11:00:00');

        // Lundi 19 avant 8 h : pas encore.
        $this->travelTo('2026-10-19 07:00:00');
        $this->artisan('billing:weekly-report')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travelTo('2026-10-19 09:05:00');
        $this->artisan('billing:weekly-report')->expectsOutput('Rapports envoyés : 1.')->assertSuccessful();
        $this->artisan('billing:weekly-report')->expectsOutput('Rapports envoyés : 0.')->assertSuccessful();
        $this->travelTo('2026-10-21 15:00:00');
        $this->artisan('billing:weekly-report')->expectsOutput('Rapports envoyés : 0.')->assertSuccessful();

        $notification = $this->business->notifications()->sole();
        $this->assertSame('Votre assistant cette semaine : 45 réponses', $notification->title);
        $this->assertSame(
            'Du 12/10 au 18/10, votre assistant a répondu 45 fois à vos clients, soit environ 1 h 30 de votre temps économisé. '
            .'Il a enregistré 1 commande pour un total de 15 000 FCFA. '
            .'Il a pris 1 rendez-vous pour un total de 5 000 FCFA.',
            $notification->body,
        );
        $this->assertEquals(['kind' => 'weekly_report', 'period_key' => '2026-10-12', 'week_start' => '2026-10-12',
            'replies' => 45, 'minutes_saved' => 90, 'orders_count' => 1, 'orders_total' => 15000, 'appointments_count' => 1,
            'appointments_total' => 5000], $notification->data);
        $this->assertSame(0, $quiet->notifications()->count());
        Mail::assertSent(OwnerNotificationMail::class, fn (OwnerNotificationMail $mail) => $mail->actionUrl === 'https://dashboard.example.test/dashboard');
        Mail::assertSentCount(1);

        // Semaine suivante : nouveau rapport.
        $this->travelTo('2026-10-26 08:00:00');
        $this->artisan('billing:weekly-report')->expectsOutput('Rapports envoyés : 1.');
        $this->assertSame('Votre assistant cette semaine : 3 réponses', $this->latestNotification()->title);
    }

    public function test_weekly_report_invites_free_businesses_to_upgrade(): void
    {
        $conversation = $this->conversation($this->business);
        $this->messages($conversation, 1, '2026-10-13 15:00:00');

        $this->travelTo('2026-10-19 09:00:00');
        $this->artisan('billing:weekly-report')->assertSuccessful();

        $notification = $this->business->notifications()->sole();
        $this->assertSame('Votre assistant cette semaine : 1 réponse', $notification->title);
        $this->assertStringContainsString('soit environ 2 minutes de votre temps', $notification->body);
        $this->assertStringContainsString('En formule Gratuit, il est limité à 10 réponses par jour', $notification->body);
    }

    public function test_commands_are_scheduled_hourly_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($event) => Str::afterLast($event->command, ' '));

        foreach (['billing:sync' => '0 * * * *', 'billing:weekly-report' => '5 * * * *'] as $command => $expression) {
            $this->assertArrayHasKey($command, $events->all());
            $this->assertSame($expression, $events[$command]->expression);
            $this->assertTrue($events[$command]->withoutOverlapping);
            $this->assertTrue($events[$command]->onOneServer);
        }

        $this->artisan('billing:sync')->expectsOutput('Abonnements vérifiés : 0, mis à jour : 0, rappels envoyés : 0.')->assertSuccessful();
    }

    public function test_email_renders_the_message_and_the_link(): void
    {
        $html = (new OwnerNotificationMail(
            'Votre essai Pro se termine demain',
            ['Première ligne.', 'Deuxième ligne.'],
            'Voir mon abonnement',
            'https://dashboard.example.test/dashboard/billing',
        ))->render();

        $this->assertStringContainsString('Votre essai Pro se termine demain', $html);
        $this->assertStringContainsString('Deuxième ligne.', $html);
        $this->assertStringContainsString('href="https://dashboard.example.test/dashboard/billing"', $html);
    }

    private function sync(): void
    {
        $this->artisan('billing:sync')->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function reminderKinds(): array
    {
        return DB::table('subscription_reminders')->where('business_id', $this->business->id)
            ->orderBy('sent_at')->pluck('kind')->all();
    }

    private function latestNotification(): Notification
    {
        return $this->business->notifications()->latest()->firstOrFail();
    }

    private function makeBusiness(string $name): Business
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => $name, 'modules' => '[]']);

        return Business::findOrFail($id);
    }

    private function conversation(Business $business): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $id, 'business_id' => $business->id, 'customer_phone' => '22670000001']);

        return $id;
    }

    private function messages(string $conversationId, int $count, string $at, bool $canned = false): void
    {
        DB::table('messages')->insert(array_map(fn () => [
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'direction' => 'outbound',
            'sender_type' => 'ai',
            'content' => 'Réponse',
            'metadata' => $canned ? json_encode(['canned' => true]) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ], range(1, $count)));
    }

    private function order(string $conversationId, int $total, string $status, string $at): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $this->business->id, 'conversation_id' => $conversationId,
            'customer_phone' => '22670000001', 'items' => '[]', 'total_amount' => $total, 'status' => $status,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function appointment(string $conversationId, int $price, string $status, string $at): void
    {
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $this->business->id, 'conversation_id' => $conversationId,
            'customer_phone' => '22670000001', 'service' => 'Coiffure', 'requested_date' => '2026-10-20',
            'requested_time' => '10:00', 'price' => $price, 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
