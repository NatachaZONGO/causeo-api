<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Résumé des ventes d'une entreprise, dans son fuseau horaire :
 * - chiffre d'affaires : total des ventes de la période (commandes confirmées, payées ou livrées,
 *   ventes au comptoir comprises), à la date de la vente ;
 * - encaissé : versements reçus pendant la période (hors commandes annulées) ;
 * - reste à encaisser : ce que doivent encore toutes les ventes non annulées, quelle que soit leur date ;
 * - évolution (7 jours, 8 semaines ou 6 mois) et meilleurs articles de la période.
 * Les commandes « new » (pas encore confirmées) et annulées ne comptent pas comme des ventes.
 */
class SalesSummaryService
{
    public const PERIODS = ['day', 'week', 'month'];

    /** Statuts qui font d'une commande une vente. */
    public const SOLD_STATUSES = ['confirmed', 'paid', 'delivered'];

    private const BUCKETS = ['day' => 7, 'week' => 8, 'month' => 6];

    /**
     * @return array<string, mixed>
     */
    public function summary(Business $business, string $period = 'day', ?Carbon $now = null): array
    {
        $timezone = $business->localTimezone();
        $now = ($now ?? now())->copy()->timezone($timezone);

        $buckets = [];
        for ($i = self::BUCKETS[$period] - 1; $i >= 0; $i--) {
            $start = $this->startOf($now->copy(), $period)->sub($i, $period);
            $buckets[] = ['from' => $start, 'to' => $this->endOf($start->copy(), $period)];
        }
        $since = $buckets[0]['from']->copy()->utc();

        $orders = $business->orders()
            ->whereIn('status', self::SOLD_STATUSES)
            ->where('created_at', '>=', $since)
            ->get(['id', 'total_amount', 'created_at', 'source']);

        $payments = OrderPayment::query()
            ->where('business_id', $business->id)
            ->where('paid_at', '>=', $since)
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->get(['amount', 'paid_at']);

        $series = array_map(fn (array $bucket) => [
            'label' => $this->label($bucket['from'], $period),
            'from' => $bucket['from']->toDateString(),
            'to' => $bucket['to']->toDateString(),
            'revenue' => $this->sum($orders, 'total_amount', 'created_at', $bucket),
            'collected' => $this->sum($payments, 'amount', 'paid_at', $bucket),
            'sales_count' => $this->within($orders, 'created_at', $bucket)->count(),
        ], $buckets);

        $current = end($buckets);
        $previous = $buckets[count($buckets) - 2] ?? null;
        $currentOrders = $this->within($orders, 'created_at', $current);

        $outstanding = $business->orders()
            ->whereIn('status', self::SOLD_STATUSES)
            ->where('payment_status', '!=', Order::PAYMENT_PAID)
            ->get(['total_amount', 'amount_paid']);

        return [
            'period' => $period,
            'timezone' => $timezone,
            'currency' => 'FCFA',
            'current' => [
                'from' => $current['from']->toDateString(),
                'to' => $current['to']->toDateString(),
                'revenue' => end($series)['revenue'],
                'collected' => end($series)['collected'],
                'sales_count' => $currentOrders->count(),
                'counter_sales_count' => $currentOrders->where('source', Order::SOURCE_COUNTER)->count(),
                'items_sold' => (int) OrderItem::query()->whereIn('order_id', $currentOrders->pluck('id'))->sum('quantity'),
            ],
            'previous' => $previous === null ? null : [
                'revenue' => $series[count($series) - 2]['revenue'],
                'collected' => $series[count($series) - 2]['collected'],
            ],
            'outstanding' => [
                'amount' => round($outstanding->sum(fn (Order $order) => max(0, (float) $order->total_amount - (float) $order->amount_paid)), 2),
                'orders_count' => $outstanding->count(),
            ],
            'series' => $series,
            'top_products' => $this->topProducts($currentOrders->pluck('id')),
        ];
    }

    /**
     * Les 5 articles les plus vendus (quantité, puis montant) parmi ces ventes.
     *
     * @param  Collection<int, string>  $orderIds
     * @return list<array{product_id: ?string, name: string, quantity: int, revenue: float}>
     */
    private function topProducts(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        return OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->get(['product_id', 'name', 'quantity', 'line_total'])
            // Article du catalogue : regroupé par article (toutes variantes) ; sinon par nom.
            ->groupBy(fn (OrderItem $line) => $line->product_id ?? 'name:'.mb_strtolower($line->name))
            ->map(fn (Collection $lines) => [
                'product_id' => $lines->first()->product_id,
                'name' => $lines->first()->name,
                'quantity' => (int) $lines->sum('quantity'),
                'revenue' => round((float) $lines->sum('line_total'), 2),
            ])
            ->sortBy([['quantity', 'desc'], ['revenue', 'desc']])
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @param  array{from: Carbon, to: Carbon}  $bucket
     */
    private function sum(Collection $rows, string $amount, string $date, array $bucket): float
    {
        return round((float) $this->within($rows, $date, $bucket)->sum(fn ($row) => (float) $row->{$amount}), 2);
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @param  array{from: Carbon, to: Carbon}  $bucket
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function within(Collection $rows, string $date, array $bucket): Collection
    {
        return $rows->filter(fn ($row) => $row->{$date} !== null && $row->{$date}->between($bucket['from'], $bucket['to']));
    }

    private function startOf(Carbon $date, string $period): Carbon
    {
        return match ($period) {
            'week' => $date->startOfWeek(Carbon::MONDAY),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    private function endOf(Carbon $date, string $period): Carbon
    {
        return match ($period) {
            'week' => $date->endOfWeek(Carbon::SUNDAY),
            'month' => $date->endOfMonth(),
            default => $date->endOfDay(),
        };
    }

    private function label(Carbon $from, string $period): string
    {
        $from = $from->copy()->locale('fr');

        return match ($period) {
            'week' => 'sem. du '.$from->isoFormat('D MMM'),
            'month' => $from->isoFormat('MMM YYYY'),
            default => $from->isoFormat('ddd D'),
        };
    }
}
