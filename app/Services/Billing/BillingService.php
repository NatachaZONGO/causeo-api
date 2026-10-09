<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Formules et abonnements. Le statut effectif est toujours calculé à partir des
 * dates (state()) : le planificateur ne fait que le persister et notifier.
 */
class BillingService
{
    /**
     * État effectif d'un business à l'instant présent.
     */
    public function state(Business $business): BillingState
    {
        $subscription = $business->relationLoaded('subscription')
            ? $business->subscription
            : $business->subscription()->with('plan')->first();

        $now = now();

        if ($subscription === null || in_array($subscription->status, ['expired', 'cancelled'], true)) {
            return $this->free($subscription);
        }

        $plan = $subscription->plan;

        if ($subscription->status === 'trialing') {
            return $subscription->trial_ends_at !== null && $now->lessThan($subscription->trial_ends_at)
                ? new BillingState($plan, 'trialing', $subscription, $subscription->trial_ends_at, null)
                : $this->free($subscription);
        }

        // Formule sans échéance (Interne).
        if ($subscription->current_period_end === null) {
            return new BillingState($plan, 'active', $subscription, null, null);
        }

        $graceEndsAt = $subscription->current_period_end->copy()->addDays(Subscription::GRACE_DAYS);

        return match (true) {
            $now->lessThan($subscription->current_period_end) => new BillingState($plan, 'active', $subscription, $subscription->current_period_end, $graceEndsAt),
            $now->lessThan($graceEndsAt) => new BillingState($plan, 'grace', $subscription, $subscription->current_period_end, $graceEndsAt),
            default => $this->free($subscription),
        };
    }

    /**
     * À la création d'un business : 30 jours de Pro offerts, sauf si l'utilisateur
     * a déjà eu un essai (le business démarre alors en Gratuit).
     */
    public function startTrial(Business $business, ?User $user): BillingState
    {
        if ($user !== null && TrialUsage::query()->where('user_id', $user->id)->exists()) {
            Log::info('BillingService: essai déjà utilisé par cet utilisateur, business en Gratuit.', [
                'business_id' => $business->id,
                'user_id' => $user->id,
            ]);

            return $this->syncPlanColumn($business);
        }

        DB::transaction(function () use ($business, $user) {
            Subscription::create([
                'business_id' => $business->id,
                'plan_id' => Plan::bySlug(Plan::PRO)->id,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(Subscription::TRIAL_DAYS),
            ]);

            TrialUsage::create(['user_id' => $user?->id, 'business_id' => $business->id]);
        });

        return $this->syncPlanColumn($business);
    }

    /**
     * À la connexion d'un numéro WhatsApp : si ce numéro a déjà servi à l'essai
     * d'un autre business, l'essai en cours s'arrête et le business passe en Gratuit.
     * Sinon, le numéro est rattaché à l'essai en cours.
     */
    public function onWhatsAppConnected(Business $business, ?string $whatsappNumber): BillingState
    {
        $number = $this->normalizeNumber($whatsappNumber);
        $subscription = $business->subscription()->first();

        if ($number === null || $subscription === null || $subscription->status !== 'trialing') {
            return $this->state($business->fresh());
        }

        $alreadyUsed = TrialUsage::query()
            ->where('whatsapp_number', $number)
            ->where(fn ($query) => $query->whereNull('business_id')->orWhere('business_id', '!=', $business->id))
            ->exists();

        if ($alreadyUsed) {
            $subscription->update(['status' => 'expired', 'ended_at' => now()]);

            Log::info('BillingService: numéro WhatsApp déjà utilisé pour un essai, business en Gratuit.', [
                'business_id' => $business->id,
            ]);
        } else {
            TrialUsage::query()->updateOrCreate(
                ['business_id' => $business->id],
                ['whatsapp_number' => $number, 'user_id' => $business->user_id],
            );
        }

        return $this->syncPlanColumn($business->fresh());
    }

    /**
     * Changement de formule par un admin : Interne (sans échéance), Gratuit, ou
     * formule payante pour une période qui commence maintenant.
     */
    public function assignPlan(Business $business, Plan $plan): BillingState
    {
        if ($plan->slug === Plan::FREE) {
            $business->subscription()->first()?->update(['status' => 'expired', 'ended_at' => now()]);

            return $this->syncPlanColumn($business->fresh());
        }

        Subscription::query()->updateOrCreate(['business_id' => $business->id], [
            'plan_id' => $plan->id,
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $plan->period_days ? now()->addDays($plan->period_days) : null,
            'ended_at' => null,
        ]);

        return $this->syncPlanColumn($business->fresh());
    }

    /**
     * Appliquer un paiement validé (à appeler dans une transaction). Même formule
     * encore active ou en grâce : la période est prolongée depuis son échéance.
     * Sinon (essai, Gratuit, autre formule, échue) : une période commence maintenant.
     *
     * @return array{subscription: Subscription, period_start: \Carbon\CarbonInterface, period_end: \Carbon\CarbonInterface}
     */
    public function applyPayment(Business $business, Plan $plan, int $months): array
    {
        $state = $this->state($business);
        $subscription = Subscription::query()->where('business_id', $business->id)->lockForUpdate()->first();
        $days = $plan->period_days * $months;

        $extends = $subscription !== null
            && $subscription->plan_id === $plan->id
            && $subscription->current_period_end !== null
            && in_array($state->status, ['active', 'grace'], true);

        if ($extends) {
            $periodStart = $subscription->current_period_end->copy();
            $subscription->update([
                'status' => 'active',
                'current_period_end' => $periodStart->copy()->addDays($days),
                'ended_at' => null,
            ]);
        } else {
            $periodStart = now();
            $subscription = Subscription::query()->updateOrCreate(['business_id' => $business->id], [
                'plan_id' => $plan->id,
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodStart->copy()->addDays($days),
                'ended_at' => null,
            ]);
        }

        $this->syncPlanColumn($business->fresh());

        return [
            'subscription' => $subscription->fresh(),
            'period_start' => $periodStart,
            'period_end' => $subscription->fresh()->current_period_end,
        ];
    }

    /**
     * Recopier la formule effective dans businesses.plan (affichage et filtres admin).
     */
    public function syncPlanColumn(Business $business): BillingState
    {
        $business->unsetRelation('subscription');
        $state = $this->state($business);

        if ($business->getRawOriginal('plan') !== $state->plan->slug) {
            $business->forceFill(['plan' => $state->plan->slug])->saveQuietly();
        }

        return $state;
    }

    /**
     * « +226 70 00 00 00 » → « 22670000000 ».
     */
    public function normalizeNumber(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        return $digits === '' ? null : $digits;
    }

    private function free(?Subscription $subscription): BillingState
    {
        return new BillingState(Plan::bySlug(Plan::FREE), 'free', $subscription, null, null);
    }
}
