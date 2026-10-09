<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lancé toutes les heures (billing:sync). Le statut effectif est calculé à partir
 * des dates par BillingService::state() ; ici on ne fait que le recopier en base
 * et prévenir le gérant. Chaque rappel n'est envoyé qu'une fois (ReminderService),
 * et un rappel manqué (serveur arrêté) est rattrapé au passage suivant.
 */
class BillingSyncService
{
    /** Rappels avant la fin d'un essai ou d'une période payée, en jours. */
    public const REMINDER_DAYS = [7, 3, 1, 0];

    /** Pas d'email la nuit (heure de Ouagadougou), sauf si l'échéance tombe avant le matin. */
    private const DAY_STARTS_AT = 8;

    private const DAY_ENDS_AT = 21;

    public function __construct(
        private readonly BillingService $billing,
        private readonly ReminderService $reminders,
    ) {
    }

    /**
     * @return array{checked: int, updated: int, reminders: int}
     */
    public function run(): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'reminders' => 0];

        Subscription::query()
            ->whereIn('status', ['trialing', 'active', 'grace'])
            ->with(['plan', 'business.user'])
            ->chunkById(100, function ($subscriptions) use (&$stats) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->business === null) {
                        continue;
                    }

                    $stats['checked']++;

                    try {
                        $this->sync($subscription, $stats);
                    } catch (Throwable $e) {
                        Log::error('BillingSyncService: synchronisation impossible', [
                            'business_id' => $subscription->business_id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * @param  array{checked: int, updated: int, reminders: int}  $stats
     */
    private function sync(Subscription $subscription, array &$stats): void
    {
        $business = $subscription->business;
        $business->setRelation('subscription', $subscription);

        $state = $this->billing->state($business);
        $wasTrial = $subscription->status === 'trialing';
        $target = $state->isFree() ? 'expired' : $state->status;

        if ($target !== $subscription->status) {
            $subscription->update([
                'status' => $target,
                'ended_at' => $target === 'expired' ? $this->endedAt($subscription) : null,
            ]);
            $stats['updated']++;
        }

        $this->billing->syncPlanColumn($business);

        if ($this->remind($business, $subscription, $state, $wasTrial)) {
            $stats['reminders']++;
        }
    }

    private function remind(Business $business, Subscription $subscription, BillingState $state, bool $wasTrial): bool
    {
        $free = Plan::bySlug(Plan::FREE);
        $freeLimit = "{$free->reply_limit} réponses automatiques par jour, sans prise de commandes ni de rendez-vous";

        if ($state->isFree()) {
            // Fin de l'essai, ou de la grâce d'une formule payante.
            $plan = $subscription->plan;
            $deadline = $wasTrial ? $subscription->trial_ends_at : $subscription->current_period_end;

            // Envoyé tout de suite, même la nuit : l'abonnement passe en « expired » et
            // ne sera plus repris par les passages suivants.
            return $deadline !== null && $this->reminders->send(
                $business,
                'downgraded',
                $deadline->toIso8601String(),
                'Votre assistant est passé en formule Gratuit',
                [
                    $wasTrial
                        ? "Votre essai de la formule {$plan->name} est terminé."
                        : "Votre formule {$plan->name} n'a pas été renouvelée.",
                    "Votre assistant reste actif, avec {$freeLimit}.",
                    'Choisissez une formule depuis votre page Abonnement pour retrouver toutes ses fonctions.',
                ],
                ['plan' => $plan->slug],
            );
        }

        if ($state->status === 'grace') {
            return $this->sendable($state->graceEndsAt, urgent: false) && $this->reminders->send(
                $business,
                'grace',
                $state->endsAt->toIso8601String(),
                "Votre formule {$state->plan->name} a expiré",
                [
                    "Votre formule {$state->plan->name} a expiré le {$this->date($state->endsAt)}. "
                        ."Votre assistant continue de répondre normalement jusqu'au {$this->dateTime($state->graceEndsAt)}.",
                    "Sans renouvellement d'ici là, il passera en formule Gratuit : {$freeLimit}.",
                    'Renouvelez depuis votre page Abonnement.',
                ],
                ['plan' => $state->plan->slug, 'grace_ends_at' => $state->graceEndsAt->toIso8601String()],
            );
        }

        // Essai ou période en cours ; la formule Interne n'a pas d'échéance.
        if ($state->endsAt === null) {
            return false;
        }

        $days = $this->calendarDaysUntil($state->endsAt);
        // Rappel le plus proche encore dû : si J-3 a été manqué à J-2, il part à J-2.
        $threshold = collect(self::REMINDER_DAYS)->sort()->first(fn (int $day) => $day >= $days);

        if ($threshold === null || ! $this->sendable($state->endsAt, urgent: true)) {
            return false;
        }

        $when = match ($days) {
            0 => 'aujourd\'hui',
            1 => 'demain',
            default => "dans {$days} jours",
        };

        [$title, $lines] = $state->isTrial()
            ? [
                "Votre essai {$state->plan->name} se termine {$when}",
                [
                    "Votre essai gratuit de la formule {$state->plan->name} prend fin le {$this->dateTime($state->endsAt)}.",
                    "Ensuite, votre assistant passe en formule Gratuit : {$freeLimit}.",
                    'Choisissez une formule depuis votre page Abonnement pour qu\'il continue de répondre à tous vos clients.',
                ],
            ]
            : [
                "Votre formule {$state->plan->name} expire {$when}",
                [
                    "Votre formule {$state->plan->name} est payée jusqu'au {$this->dateTime($state->endsAt)}.",
                    'Renouvelez-la depuis votre page Abonnement pour que votre assistant continue de répondre sans interruption.',
                    'Après l\'échéance, vous gardez '.Subscription::GRACE_DAYS.' jours de grâce, puis l\'assistant passe en formule Gratuit.',
                ],
            ];

        return $this->reminders->send(
            $business,
            "reminder_{$threshold}",
            $state->endsAt->toIso8601String(),
            $title,
            $lines,
            ['plan' => $state->plan->slug, 'status' => $state->status, 'days_left' => $days, 'ends_at' => $state->endsAt->toIso8601String()],
        );
    }

    /**
     * Date de fin effective : fin de l'essai, ou fin de la grâce d'une formule payante.
     */
    private function endedAt(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->status === 'trialing') {
            return $subscription->trial_ends_at;
        }

        return $subscription->current_period_end?->copy()->addDays(Subscription::GRACE_DAYS);
    }

    /**
     * Jours calendaires (Ouagadougou) entre aujourd'hui et l'échéance.
     */
    private function calendarDaysUntil(CarbonInterface $deadline): int
    {
        $today = CarbonImmutable::now(UsageService::TIMEZONE)->startOfDay();
        $day = CarbonImmutable::instance($deadline)->setTimezone(UsageService::TIMEZONE)->startOfDay();

        return (int) round($today->diffInDays($day, false));
    }

    /**
     * Pas d'envoi la nuit ; un rappel urgent part quand même si l'échéance tombe
     * avant le matin (sinon il arriverait trop tard).
     */
    private function sendable(?CarbonInterface $deadline, bool $urgent): bool
    {
        $now = CarbonImmutable::now(UsageService::TIMEZONE);

        if ($now->hour >= self::DAY_STARTS_AT && $now->hour < self::DAY_ENDS_AT) {
            return true;
        }

        if (! $urgent || $deadline === null) {
            return false;
        }

        $morning = $now->hour >= self::DAY_ENDS_AT
            ? $now->addDay()->setTime(self::DAY_STARTS_AT, 0)
            : $now->setTime(self::DAY_STARTS_AT, 0);

        return $deadline->lessThan($morning);
    }

    private function date(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(UsageService::TIMEZONE)->format('d/m/Y');
    }

    private function dateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(UsageService::TIMEZONE)->format('d/m/Y à H\hi');
    }
}
