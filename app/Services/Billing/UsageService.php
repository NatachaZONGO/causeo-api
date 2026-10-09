<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Limites de réponses automatiques : calcul de l'usage depuis la table messages,
 * blocage des réponses une fois la limite atteinte et notifications au gérant.
 */
class UsageService
{
    /** Le compteur quotidien du Gratuit repart à zéro à minuit, heure de Ouagadougou. */
    public const TIMEZONE = 'Africa/Ouagadougou';

    /** Statut d'un message entrant resté sans réponse automatique (limite atteinte). */
    public const LIMIT_REACHED = 'limit_reached';

    /** Seuils de consommation notifiés pour une formule à limite mensuelle. */
    private const THRESHOLDS = [80, 100];

    public function __construct(
        private readonly BillingService $billing,
    ) {
    }

    public function usage(Business $business, ?BillingState $state = null): Usage
    {
        $state ??= $this->billing->state($business);
        $plan = $state->plan;

        if ($plan->reply_limit === null) {
            return new Usage(null, 0, null, null, null);
        }

        [$startsAt, $resetsAt] = $plan->reply_limit_period === 'day'
            ? $this->today()
            : $this->period($state);

        return new Usage($plan->reply_limit, $this->repliesSince($business, $startsAt), $plan->reply_limit_period, $startsAt, $resetsAt);
    }

    /**
     * Le bot peut-il encore répondre automatiquement à ce business ?
     */
    public function allowsReply(Business $business): bool
    {
        return ! $this->usage($business)->isExhausted();
    }

    /**
     * Limite atteinte : le message entrant est gardé (le gérant le voit et peut
     * répondre), mais sans réponse automatique. Une seule notification par jour
     * indique combien de clients sont concernés.
     */
    public function recordMissedReply(Business $business, Message $inbound): void
    {
        $inbound->update(['status' => self::LIMIT_REACHED]);

        try {
            $state = $this->billing->state($business);
            $usage = $this->usage($business, $state);
            [$dayStart] = $this->today();
            $date = $dayStart->setTimezone(self::TIMEZONE)->toDateString();

            $missed = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.business_id', $business->id)
                ->where('messages.direction', 'inbound')
                ->where('messages.status', self::LIMIT_REACHED)
                ->where('messages.created_at', '>=', $dayStart)
                ->distinct()
                ->count('conversations.customer_phone');

            $clients = $missed > 1 ? "{$missed} clients n'ont" : "1 client n'a";
            $upgrade = $this->upgradeFor($state->plan);
            $body = $usage->window === 'day'
                ? "Vos {$usage->limit} réponses automatiques du jour sont utilisées : {$clients} pas reçu de réponse automatique aujourd'hui. "
                    .'Répondez-leur depuis WhatsApp'.($upgrade ? ", ou passez en {$upgrade->name} pour que l'assistant réponde à tous vos clients." : '.')
                : "Les {$usage->limit} réponses de votre formule {$state->plan->name} sont utilisées"
                    .($usage->resetsAt ? ' jusqu\'au '.$usage->resetsAt->setTimezone(self::TIMEZONE)->format('d/m/Y') : '')
                    ." : {$clients} pas reçu de réponse automatique aujourd'hui. "
                    .'Répondez-leur depuis WhatsApp'.($upgrade ? ", ou passez en {$upgrade->name} pour que l'assistant continue de répondre." : '.');

            $attributes = [
                'title' => ($missed > 1 ? "{$missed} clients" : '1 client').' sans réponse automatique aujourd\'hui',
                'body' => $body,
                'data' => ['kind' => 'replies_missed', 'date' => $date, 'missed_customers' => $missed, 'plan' => $state->plan->slug],
            ];

            $existing = Notification::query()
                ->where('business_id', $business->id)
                ->where('type', 'billing')
                ->where('data->kind', 'replies_missed')
                ->where('data->date', $date)
                ->first();

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                Notification::create($attributes + ['business_id' => $business->id, 'type' => 'billing']);
            }
        } catch (Throwable $e) {
            Log::error('UsageService::recordMissedReply : notification impossible', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Après une réponse du bot : prévenir le gérant à 80 % et à 100 % de sa
     * limite mensuelle (une fois par seuil et par période).
     */
    public function afterReply(Business $business): void
    {
        try {
            $state = $this->billing->state($business);
            $usage = $this->usage($business, $state);

            if ($usage->window !== 'period' || $usage->percent() === null) {
                return;
            }

            $periodKey = $usage->startsAt->toIso8601String();

            foreach (self::THRESHOLDS as $threshold) {
                if ($usage->percent() < $threshold) {
                    continue;
                }

                $kind = "usage_{$threshold}";
                $alreadyNotified = Notification::query()
                    ->where('business_id', $business->id)
                    ->where('type', 'billing')
                    ->where('data->kind', $kind)
                    ->where('data->period_start', $periodKey)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                Notification::create([
                    'business_id' => $business->id,
                    'type' => 'billing',
                    ...$this->thresholdMessage($threshold, $state, $usage),
                    'data' => ['kind' => $kind, 'period_start' => $periodKey, 'plan' => $state->plan->slug],
                ]);
            }
        } catch (Throwable $e) {
            Log::error('UsageService::afterReply : notification impossible', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{title: string, body: string}
     */
    private function thresholdMessage(int $threshold, BillingState $state, Usage $usage): array
    {
        $plan = $state->plan->name;
        $until = $usage->resetsAt ? $usage->resetsAt->setTimezone(self::TIMEZONE)->format('d/m/Y') : null;
        $upgrade = $this->upgradeFor($state->plan);
        $advice = $upgrade ? " Passez en {$upgrade->name} pour que l'assistant continue de répondre à tous vos clients." : '';

        if ($threshold < 100) {
            return [
                'title' => "{$threshold} % de vos réponses automatiques utilisées",
                'body' => "Vous avez utilisé {$usage->used} des {$usage->limit} réponses de votre formule {$plan}"
                    .($until ? " (période jusqu'au {$until})" : '')
                    .'. Une fois la limite atteinte, l\'assistant ne répondra plus automatiquement jusqu\'au renouvellement.'.$advice,
            ];
        }

        return [
            'title' => 'Toutes vos réponses automatiques sont utilisées',
            'body' => "Les {$usage->limit} réponses de votre formule {$plan} sont utilisées"
                .($until ? " jusqu'au {$until}" : '')
                .'. Les nouveaux messages de vos clients restent visibles, mais l\'assistant n\'y répond plus automatiquement.'.$advice,
        ];
    }

    /**
     * Formule suivante à proposer (Gratuit → Starter → Pro → Business).
     */
    private function upgradeFor(Plan $plan): ?Plan
    {
        $next = match ($plan->slug) {
            Plan::FREE => Plan::STARTER,
            Plan::STARTER => Plan::PRO,
            Plan::PRO => Plan::BUSINESS,
            default => null,
        };

        return $next ? Plan::query()->where('slug', $next)->first() : null;
    }

    /**
     * Journée en cours à Ouagadougou, en UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function today(): array
    {
        $start = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * Période en cours : essai (30 jours avant sa fin) ou période payée.
     *
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(BillingState $state): array
    {
        $subscription = $state->subscription;

        if ($state->status === 'trialing') {
            $end = CarbonImmutable::instance($subscription->trial_ends_at);

            return [$end->subDays(Subscription::TRIAL_DAYS), $end];
        }

        $start = $subscription?->current_period_start
            ? CarbonImmutable::instance($subscription->current_period_start)
            : CarbonImmutable::now()->startOfDay();

        $end = $state->status === 'grace' ? $state->graceEndsAt : $state->endsAt;

        return [$start, $end ? CarbonImmutable::instance($end) : null];
    }

    /**
     * Réponses générées par l'IA (messages d'attente des escalades compris) ; les
     * réponses toutes faites aux salutations et remerciements (metadata.canned),
     * qui ne coûtent aucun appel à Claude, ne comptent pas.
     */
    private function repliesSince(Business $business, CarbonImmutable $since): int
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.business_id', $business->id)
            ->where('messages.direction', 'outbound')
            ->where('messages.sender_type', 'ai')
            ->whereNull('messages.metadata->canned')
            ->where('messages.created_at', '>=', $since)
            ->count();
    }
}
