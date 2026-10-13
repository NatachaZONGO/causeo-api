<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Limites en unités (1 unité = 1 réponse de l'assistant) : calcul de l'usage depuis
 * la table messages, blocage des réponses une fois la limite atteinte, fin de l'essai
 * quand ses unités sont épuisées, et notifications au gérant.
 */
class UsageService
{
    /** Le compteur quotidien du Gratuit repart à zéro à minuit, heure de Ouagadougou. */
    public const TIMEZONE = 'Africa/Ouagadougou';

    /** Statut d'un message entrant resté sans réponse automatique (limite atteinte). */
    public const LIMIT_REACHED = 'limit_reached';

    /** Page Abonnement du dashboard, vers laquelle pointent les notifications. */
    public const BILLING_PATH = '/dashboard/billing';

    /** Seuils de consommation notifiés pour une formule à limite mensuelle. */
    private const THRESHOLDS = [80, 100];

    public function __construct(
        private readonly BillingService $billing,
        private readonly ReminderService $reminders,
    ) {
    }

    public function usage(Business $business, ?BillingState $state = null): Usage
    {
        $state ??= $this->billing->state($business);
        $plan = $state->plan;
        $limit = $state->isTrial()
            ? ($state->subscription?->trial_unit_limit ?? $plan->reply_limit)
            : $plan->reply_limit;

        if ($limit === null) {
            return new Usage(null, 0, null, null, null);
        }

        [$startsAt, $resetsAt] = $plan->reply_limit_period === 'day' && ! $state->isTrial()
            ? $this->today($state)
            : $this->period($state);

        return new Usage($limit, $this->repliesSince($business, $startsAt), $state->isTrial() ? 'period' : $plan->reply_limit_period, $startsAt, $resetsAt);
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
                ? "Vos {$usage->limit} unités du jour sont utilisées : {$clients} pas reçu de réponse automatique aujourd'hui. "
                    .'Répondez-leur depuis WhatsApp'.($upgrade ? ", ou passez en {$upgrade->name} pour que l'assistant réponde à tous vos clients." : '.')
                : "Les {$usage->limit} unités de votre formule {$state->plan->name} sont utilisées"
                    .($usage->resetsAt ? ' jusqu\'au '.$usage->resetsAt->setTimezone(self::TIMEZONE)->format('d/m/Y') : '')
                    ." : {$clients} pas reçu de réponse automatique aujourd'hui. "
                    .'Répondez-leur depuis WhatsApp'.($upgrade ? ", ou passez en {$upgrade->name} pour que l'assistant continue de répondre." : '.');

            $attributes = [
                'title' => ($missed > 1 ? "{$missed} clients" : '1 client').' sans réponse automatique aujourd\'hui',
                'body' => $body,
                'data' => ['kind' => 'replies_missed', 'date' => $date, 'missed_customers' => $missed, 'plan' => $state->plan->slug, ...$this->billingLink()],
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
     * Après une réponse du bot : pendant l'essai, notifier à 80 % de ses unités et le
     * terminer à 100 % ; sur une formule payante, prévenir à 80 % et à 100 % de la
     * limite mensuelle (une fois par seuil et par période).
     */
    public function afterReply(Business $business): void
    {
        try {
            $state = $this->billing->state($business);

            if ($state->isTrial()) {
                $this->checkTrialUnits($business, $state);

                return;
            }

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
                    'data' => ['kind' => $kind, 'period_start' => $periodKey, 'plan' => $state->plan->slug, ...$this->billingLink()],
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
     * Unités de l'essai : notification à 80 %, et à 100 % fin de l'essai (passage en
     * Gratuit, comme à la fin de sa durée) avec sa notification. Chaque seuil n'est
     * notifié qu'une fois (ReminderService). Appelé après chaque réponse et par
     * billing:sync, qui rattrape un seuil manqué.
     *
     * @return bool true si l'essai vient de se terminer
     */
    public function checkTrialUnits(Business $business, ?BillingState $state = null): bool
    {
        $state ??= $this->billing->state($business);

        if (! $state->isTrial() || $state->subscription?->trial_unit_limit === null) {
            return false; // essai de l'ancienne offre : limite du Pro, sans fin anticipée
        }

        $usage = $this->usage($business, $state);
        $percent = $usage->percent() ?? 0;
        $thresholds = collect((array) config('billing.trial_unit_thresholds', [80, 100]))->map(fn ($t) => (int) $t)->sort()->values();
        $periodKey = $usage->startsAt->toIso8601String();
        $data = ['plan' => $state->plan->slug, 'units_used' => $usage->used, 'units_limit' => $usage->limit];

        if ($usage->isExhausted()) {
            $this->billing->endTrial($business);
            $free = Plan::bySlug(Plan::FREE);

            $this->reminders->send(
                $business,
                'trial_units_100',
                $periodKey,
                "Votre essai {$state->plan->name} est terminé : {$usage->limit} unités utilisées",
                [
                    "Les {$usage->limit} unités de votre essai {$state->plan->name} sont utilisées (1 unité = 1 réponse de l'assistant).",
                    "Votre assistant reste actif en formule Gratuit : {$free->reply_limit} unités par jour, sans prise de commandes ni de rendez-vous.",
                    'Choisissez une formule depuis votre page Abonnement pour retrouver toutes ses fonctions.',
                ],
                $data + ['threshold' => 100],
            );

            return true;
        }

        // Le seuil atteint le plus haut, sous 100 % (rattrapage compris : on ne notifie pas 50 % à 85 %).
        $threshold = $thresholds->filter(fn (int $t) => $t < 100 && $percent >= $t)->last();

        if ($threshold !== null) {
            $free = Plan::bySlug(Plan::FREE);

            $this->reminders->send(
                $business,
                "trial_units_{$threshold}",
                $periodKey,
                "{$threshold} % des unités de votre essai utilisées",
                [
                    "Vous avez utilisé {$usage->used} des {$usage->limit} unités de votre essai {$state->plan->name} (1 unité = 1 réponse de l'assistant).",
                    "L'essai se termine à {$usage->limit} unités ou le {$this->dateTime($state->endsAt)}, selon ce qui arrive en premier. "
                        ."Votre assistant passera alors en formule Gratuit : {$free->reply_limit} unités par jour.",
                    'Choisissez une formule depuis votre page Abonnement pour qu\'il continue de répondre à tous vos clients.',
                ],
                $data + ['threshold' => $threshold],
            );
        }

        return false;
    }

    /**
     * Lien vers la page Abonnement, ajouté aux données de chaque notification.
     *
     * @return array{action_path: string, action_url: string}
     */
    public function billingLink(): array
    {
        return [
            'action_path' => self::BILLING_PATH,
            'action_url' => rtrim((string) config('app.frontend_url'), '/').self::BILLING_PATH,
        ];
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
                'title' => "{$threshold} % de vos unités utilisées",
                'body' => "Vous avez utilisé {$usage->used} des {$usage->limit} unités de votre formule {$plan}"
                    .($until ? " (période jusqu'au {$until})" : '')
                    .'. Une fois la limite atteinte, l\'assistant ne répondra plus automatiquement jusqu\'au renouvellement.'.$advice,
            ];
        }

        return [
            'title' => 'Toutes vos unités sont utilisées',
            'body' => "Les {$usage->limit} unités de votre formule {$plan} sont utilisées"
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
     * Journée en cours à Ouagadougou, en UTC. Le jour où un essai ou une formule se
     * termine, le Gratuit ne compte que les réponses données après le passage en Gratuit.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function today(?BillingState $state = null): array
    {
        $start = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $end = $start->addDay()->utc();
        $start = $start->utc();

        $freeSince = $state?->isFree() ? $this->freeSince($state->subscription) : null;

        if ($freeSince !== null && $freeSince->greaterThan($start) && $freeSince->lessThan($end)) {
            // Seconde suivante : la dernière réponse de l'essai peut porter la même heure que la bascule.
            $start = $freeSince->addSecond();
        }

        return [$start, $end];
    }

    /**
     * Moment du passage en Gratuit : fin enregistrée, sinon fin de l'essai ou de la grâce.
     */
    private function freeSince(?Subscription $subscription): ?CarbonImmutable
    {
        $since = $subscription?->ended_at
            ?? ($subscription?->status === 'trialing'
                ? $subscription->trial_ends_at
                : $subscription?->current_period_end?->copy()->addDays(Subscription::GRACE_DAYS));

        return $since ? CarbonImmutable::instance($since)->utc() : null;
    }

    /**
     * Période en cours : essai (de son début à sa fin) ou période payée.
     *
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(BillingState $state): array
    {
        $subscription = $state->subscription;

        if ($state->isTrial()) {
            return [$subscription->trialStartedAt(), CarbonImmutable::instance($subscription->trial_ends_at)];
        }

        $start = $subscription?->current_period_start
            ? CarbonImmutable::instance($subscription->current_period_start)
            : CarbonImmutable::now()->startOfDay();

        $end = $state->status === 'grace' ? $state->graceEndsAt : $state->endsAt;

        return [$start, $end ? CarbonImmutable::instance($end) : null];
    }

    private function dateTime(?CarbonInterface $date): string
    {
        return $date ? CarbonImmutable::instance($date)->setTimezone(self::TIMEZONE)->format('d/m/Y à H\hi') : '';
    }

    /**
     * Unités consommées : réponses générées par l'IA (messages d'attente des escalades
     * compris) ; les réponses toutes faites aux salutations et remerciements
     * (metadata.canned), qui ne coûtent aucun appel à Claude, ne comptent pas.
     */
    public function repliesSince(Business $business, CarbonInterface $since, ?CarbonInterface $until = null): int
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.business_id', $business->id)
            ->where('messages.direction', 'outbound')
            ->where('messages.sender_type', 'ai')
            ->whereNull('messages.metadata->canned')
            ->where('messages.created_at', '>=', $since)
            ->when($until !== null, fn ($query) => $query->where('messages.created_at', '<', $until))
            ->count();
    }
}
