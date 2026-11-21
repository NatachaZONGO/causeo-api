<?php

namespace App\Services\Customers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Sales\SalesSummaryService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Clients d'une entreprise avec leurs chiffres, calculés à la lecture (rien n'est stocké, donc
 * rien ne se désynchronise quand une commande est annulée, réactivée ou payée) :
 *
 * - orders_count, total_spent : commandes confirmées, payées ou livrées (les mêmes que le résumé
 *   des ventes) ; ni les annulées, ni les « new » de l'assistant, comptées à part
 *   (pending_orders_count) ;
 * - balance_due : reste à payer sur ces mêmes commandes (crédit, paiement partiel) ;
 * - last_message_at : dernier message de ses conversations ; last_activity_at : dernier message ou
 *   dernière commande ;
 * - origin : WhatsApp, comptoir (ou saisie manuelle), ou les deux.
 *
 * Requêtes compatibles PostgreSQL et SQLite (tests) : agrégats groupés joints, sans FILTER ni LATERAL.
 */
class CustomerListQuery
{
    public const SORTS = ['recent', 'name', 'spent', 'due', 'last_message'];

    public const PER_PAGE = 20;

    /**
     * @return Builder<Customer>
     */
    public static function base(Business $business): Builder
    {
        $sold = "'".implode("','", SalesSummaryService::SOLD_STATUSES)."'";
        $counter = "'".Order::SOURCE_COUNTER."','".Order::SOURCE_MANUAL."'";

        $orders = DB::table('orders')
            ->where('business_id', $business->id)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw("customer_id,
                SUM(CASE WHEN status IN ({$sold}) THEN 1 ELSE 0 END) AS orders_count,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS pending_orders_count,
                SUM(CASE WHEN status IN ({$sold}) THEN total_amount ELSE 0 END) AS total_spent,
                SUM(CASE WHEN status IN ({$sold}) AND total_amount > amount_paid THEN total_amount - amount_paid ELSE 0 END) AS balance_due,
                MAX(CASE WHEN source IN ({$counter}) THEN 1 ELSE 0 END) AS has_counter,
                MAX(CASE WHEN source = '".Order::SOURCE_AI."' THEN 1 ELSE 0 END) AS has_assistant,
                MAX(created_at) AS last_order_at");

        $conversations = DB::table('conversations')
            ->where('business_id', $business->id)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS conversations_count, MAX(COALESCE(last_message_at, created_at)) AS last_message_at');

        return Customer::query()
            ->where('customers.business_id', $business->id)
            ->leftJoinSub($orders, 'o', 'o.customer_id', '=', 'customers.id')
            ->leftJoinSub($conversations, 'c', 'c.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw('COALESCE(o.orders_count, 0) AS orders_count')
            ->selectRaw('COALESCE(o.pending_orders_count, 0) AS pending_orders_count')
            ->selectRaw('COALESCE(o.total_spent, 0) AS total_spent')
            ->selectRaw('COALESCE(o.balance_due, 0) AS balance_due')
            ->selectRaw('COALESCE(o.has_counter, 0) AS has_counter')
            ->selectRaw('COALESCE(o.has_assistant, 0) AS has_assistant')
            ->selectRaw('COALESCE(c.conversations_count, 0) AS conversations_count')
            ->selectRaw('c.last_message_at AS last_message_at')
            ->selectRaw('o.last_order_at AS last_order_at')
            ->selectRaw('('.self::lastActivity().') AS last_activity_at')
            ->withCasts([
                'orders_count' => 'integer',
                'pending_orders_count' => 'integer',
                'total_spent' => 'float',
                'balance_due' => 'float',
                'has_counter' => 'boolean',
                'has_assistant' => 'boolean',
                'conversations_count' => 'integer',
                'last_message_at' => 'datetime',
                'last_order_at' => 'datetime',
                'last_activity_at' => 'datetime',
            ]);
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function filter(Builder $query, Business $business, ?string $search, bool $owes): Builder
    {
        $search = trim((string) $search);
        if ($search !== '') {
            self::search($query, $business, $search);
        }

        if ($owes) {
            $query->whereRaw('COALESCE(o.balance_due, 0) > 0');
        }

        return $query;
    }

    /**
     * Tri demandé, puis un ordre stable (id) pour la pagination. Les valeurs vides vont à la fin.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function sort(Builder $query, string $sort, string $direction): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $name = "LOWER(COALESCE(NULLIF(TRIM(customers.name), ''), NULLIF(TRIM(customers.whatsapp_name), '')))";

        match ($sort) {
            'name' => $query->orderByRaw("CASE WHEN {$name} IS NULL THEN 1 ELSE 0 END")->orderByRaw("{$name} {$direction}")->orderBy('customers.phone', $direction),
            'spent' => $query->orderByRaw("COALESCE(o.total_spent, 0) {$direction}"),
            'due' => $query->orderByRaw("COALESCE(o.balance_due, 0) {$direction}"),
            'last_message' => $query->orderByRaw('CASE WHEN c.last_message_at IS NULL THEN 1 ELSE 0 END')->orderByRaw("c.last_message_at {$direction}"),
            default => $query->orderByRaw('CASE WHEN ('.self::lastActivity().') IS NULL THEN 1 ELSE 0 END')->orderByRaw('('.self::lastActivity().") {$direction}"),
        };

        return $query->orderBy('customers.id');
    }

    /**
     * Nombre de clients et ceux qui doivent de l'argent (en-tête de la page), sur toute la liste.
     *
     * @return array{customers_count: int, owing_count: int, owing_total: float}
     */
    public static function totals(Business $business): array
    {
        $row = DB::query()
            ->fromSub(self::base($business)->toBase(), 'clients')
            ->selectRaw('COUNT(*) AS customers_count')
            ->selectRaw('SUM(CASE WHEN balance_due > 0 THEN 1 ELSE 0 END) AS owing_count')
            ->selectRaw('SUM(balance_due) AS owing_total')
            ->first();

        return [
            'customers_count' => (int) ($row->customers_count ?? 0),
            'owing_count' => (int) ($row->owing_count ?? 0),
            'owing_total' => round((float) ($row->owing_total ?? 0), 2),
        ];
    }

    /**
     * Ligne de la liste (et en-tête de la fiche).
     *
     * @return array<string, mixed>
     */
    public static function present(Customer $customer): array
    {
        $whatsapp = $customer->conversations_count > 0 || $customer->has_assistant;

        return [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'display_name' => $customer->displayName(),
            'name' => $customer->name,
            'whatsapp_name' => $customer->whatsapp_name,
            'origin' => match (true) {
                $whatsapp && $customer->has_counter => Customer::ORIGIN_BOTH,
                $customer->has_counter => Customer::ORIGIN_COUNTER,
                default => Customer::ORIGIN_WHATSAPP,
            },
            'first_seen_at' => $customer->first_seen_at?->toIso8601String(),
            'last_message_at' => $customer->last_message_at?->toIso8601String(),
            'last_activity_at' => $customer->last_activity_at?->toIso8601String(),
            'orders_count' => (int) $customer->orders_count,
            'pending_orders_count' => (int) $customer->pending_orders_count,
            'total_spent' => round((float) $customer->total_spent, 2),
            'balance_due' => round((float) $customer->balance_due, 2),
            'has_note' => trim((string) $customer->note) !== '',
        ];
    }

    /** Dernier message ou dernière commande, à défaut la première apparition. */
    private static function lastActivity(): string
    {
        return 'CASE
            WHEN c.last_message_at IS NULL AND o.last_order_at IS NULL THEN customers.first_seen_at
            WHEN c.last_message_at IS NULL THEN o.last_order_at
            WHEN o.last_order_at IS NULL OR c.last_message_at >= o.last_order_at THEN c.last_message_at
            ELSE o.last_order_at END';
    }

    /**
     * Nom (commerçant ou WhatsApp) ou numéro, avec ou sans espaces, « + » ou indicatif : « 70 12 34 56 »
     * trouve « 22670123456 », « 06 12 34 56 78 » trouve « 33612345678 » en France.
     *
     * @param  Builder<Customer>  $query
     */
    private static function search(Builder $query, Business $business, string $search): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";
        $digits = (string) preg_replace('/\D/', '', $search);
        $normalized = PhoneNumber::normalize($search, $business->country);

        $query->where(function ($query) use ($pattern, $like, $digits, $normalized) {
            $query->whereRaw("LOWER(COALESCE(customers.name, '')) {$like}", [$pattern])
                ->orWhereRaw("LOWER(COALESCE(customers.whatsapp_name, '')) {$like}", [$pattern]);

            if (strlen($digits) >= 3) {
                $query->orWhere('customers.phone', 'like', '%'.$digits.'%');
            }
            if ($normalized !== null) {
                $query->orWhere('customers.phone', $normalized);
            }
        });
    }
}
