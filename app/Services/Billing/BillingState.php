<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonInterface;

/**
 * État de facturation effectif d'un business, calculé à partir des dates.
 *
 * status : trialing (essai Pro), active (formule payante ou Interne en cours),
 * grace (formule payante échue depuis moins de GRACE_DAYS jours) ou free.
 */
final readonly class BillingState
{
    public function __construct(
        public Plan $plan,
        public string $status,
        public ?Subscription $subscription,
        public ?CarbonInterface $endsAt,
        public ?CarbonInterface $graceEndsAt,
    ) {
    }

    public function isFree(): bool
    {
        return $this->status === 'free';
    }

    public function isTrial(): bool
    {
        return $this->status === 'trialing';
    }

    /**
     * Jours restants avant l'échéance (essai ou période payée), arrondis au jour
     * supérieur ; pendant la grâce, jours restants avant le passage en Gratuit.
     */
    public function daysLeft(?CarbonInterface $now = null): ?int
    {
        $deadline = $this->status === 'grace' ? $this->graceEndsAt : $this->endsAt;

        if ($deadline === null) {
            return null;
        }

        $now ??= now();

        return max(0, (int) ceil($now->diffInSeconds($deadline, false) / 86400));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'plan' => [
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'price_fcfa' => $this->plan->price_fcfa,
                'period_days' => $this->plan->period_days,
                'reply_limit' => $this->plan->reply_limit,
                'reply_limit_period' => $this->plan->reply_limit_period,
                'document_limit' => $this->plan->document_limit,
                'modules' => $this->plan->modules,
                'learning' => $this->plan->learning,
            ],
            'ends_at' => $this->endsAt?->toIso8601String(),
            'grace_ends_at' => $this->graceEndsAt?->toIso8601String(),
            'days_left' => $this->daysLeft(),
        ];
    }
}
