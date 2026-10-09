<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rapport de la semaine écoulée (lundi → dimanche, heure de Ouagadougou), envoyé
 * à partir du lundi 8 h. Lancé toutes les heures : un rapport manqué part au
 * passage suivant, et jamais deux fois (ReminderService).
 */
class WeeklyReportService
{
    private const SENDS_FROM_HOUR = 8;

    private const SENDS_UNTIL_HOUR = 21;

    public function __construct(
        private readonly BillingService $billing,
        private readonly UsageService $usage,
        private readonly ReminderService $reminders,
    ) {
    }

    /**
     * @return int nombre de rapports envoyés
     */
    public function run(): int
    {
        $now = CarbonImmutable::now(UsageService::TIMEZONE);
        $to = $now->startOfWeek(CarbonImmutable::MONDAY);
        $from = $to->subWeek();

        // Lundi avant 8 h, ou la nuit : on attend.
        if ($now->lessThan($to->setTime(self::SENDS_FROM_HOUR, 0)) || $now->hour < self::SENDS_FROM_HOUR || $now->hour >= self::SENDS_UNTIL_HOUR) {
            return 0;
        }

        $sent = 0;

        Business::query()
            ->where('is_active', true)
            ->with('user')
            ->chunkById(100, function ($businesses) use ($from, $to, &$sent) {
                foreach ($businesses as $business) {
                    try {
                        if ($this->report($business, $from, $to)) {
                            $sent++;
                        }
                    } catch (Throwable $e) {
                        Log::error('WeeklyReportService: rapport impossible', [
                            'business_id' => $business->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }

    /**
     * @return array{replies: int, minutes_saved: int, orders_count: int, orders_total: float, appointments_count: int, appointments_total: float}
     */
    public function stats(Business $business, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->utc(), $to->utc()];
        $replies = $this->usage->repliesSince($business, $start, $end);

        $orders = DB::table('orders')
            ->where('business_id', $business->id)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('count(*) as count, coalesce(sum(total_amount), 0) as total')
            ->first();

        $appointments = DB::table('appointments')
            ->where('business_id', $business->id)
            ->whereNotIn('status', ['declined', 'cancelled'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('count(*) as count, coalesce(sum(price), 0) as total')
            ->first();

        return [
            'replies' => $replies,
            'minutes_saved' => $replies * Plan::MINUTES_PER_REPLY,
            'orders_count' => (int) $orders->count,
            'orders_total' => (float) $orders->total,
            'appointments_count' => (int) $appointments->count,
            'appointments_total' => (float) $appointments->total,
        ];
    }

    private function report(Business $business, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $stats = $this->stats($business, $from, $to);

        // Pas de rapport pour une semaine sans activité.
        if ($stats['replies'] === 0 && $stats['orders_count'] === 0 && $stats['appointments_count'] === 0) {
            return false;
        }

        $currency = $business->currency();
        $period = 'Du '.$from->format('d/m').' au '.$to->subDay()->format('d/m');
        $lines = [
            "{$period}, votre assistant a répondu {$stats['replies']} fois à vos clients, soit environ "
                .$this->duration($stats['minutes_saved']).' de votre temps économisé.',
        ];

        if ($stats['orders_count'] > 0) {
            $lines[] = 'Il a enregistré '.$this->plural($stats['orders_count'], 'commande', 'commandes')
                .($stats['orders_total'] > 0 ? ' pour un total de '.$this->money($stats['orders_total'], $currency) : '').'.';
        }

        if ($stats['appointments_count'] > 0) {
            $lines[] = 'Il a pris '.$this->plural($stats['appointments_count'], 'rendez-vous', 'rendez-vous')
                .($stats['appointments_total'] > 0 ? ' pour un total de '.$this->money($stats['appointments_total'], $currency) : '').'.';
        }

        $state = $this->billing->state($business);

        if ($state->isFree()) {
            $lines[] = "En formule Gratuit, il est limité à {$state->plan->reply_limit} réponses par jour : "
                .'passez à une formule payante pour qu\'il réponde à tous vos clients et prenne leurs commandes.';
        }

        return $this->reminders->send(
            $business,
            'weekly_report',
            $from->toDateString(),
            'Votre assistant cette semaine : '.$this->plural($stats['replies'], 'réponse', 'réponses'),
            $lines,
            ['week_start' => $from->toDateString(), ...$stats],
            '/dashboard',
            'Ouvrir mon tableau de bord',
        );
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $this->plural($minutes, 'minute', 'minutes');
        }

        $rest = $minutes % 60;

        return intdiv($minutes, 60).' h'.($rest > 0 ? ' '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }

    private function money(float $amount, string $currency): string
    {
        $label = $currency === Currency::XOF ? 'FCFA' : $currency;
        $decimals = $currency === Currency::XOF || floor($amount) === $amount ? 0 : 2;

        return number_format($amount, $decimals, ',', ' ')." {$label}";
    }

    private function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count > 1 ? $many : $one);
    }
}
