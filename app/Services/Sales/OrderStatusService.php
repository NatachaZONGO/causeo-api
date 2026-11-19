<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\StockMovement;
use App\Services\Catalog\CatalogService;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Statut des commandes : passages autorisés (Order::TRANSITIONS), date de chaque statut, effets
 * sur le stock (annulation, réactivation) et sur les paiements (« payée » solde la commande), et
 * expiration des commandes de l'assistant restées « new » trop longtemps (orders:expire).
 */
class OrderStatusService
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws InvalidArgumentException message destiné au gérant
     */
    public function change(Order $order, string $status, ?int $userId = null, ?string $reason = null): Order
    {
        if (! $order->canMoveTo($status)) {
            $allowed = array_map(fn (string $next) => self::LABELS[$next], Order::TRANSITIONS[$order->status] ?? []);

            throw new InvalidArgumentException('Une commande « '.self::LABELS[$order->status].' » ne peut pas passer à « '.self::LABELS[$status].' »'
                .($allowed === [] ? '.' : ' : statuts possibles, '.implode(', ', $allowed).'.'));
        }

        if ($status === $order->status) {
            return $order;
        }

        return DB::transaction(function () use ($order, $status, $userId, $reason) {
            $previous = $order->status;
            $attributes = ['status' => $status];
            if (isset(Order::STATUS_DATES[$status])) {
                $attributes[Order::STATUS_DATES[$status]] = now();
            }
            if ($status === 'cancelled') {
                $attributes['cancellation_reason'] = $reason;
            } elseif ($previous === 'cancelled') {
                $attributes += ['cancelled_at' => null, 'cancellation_reason' => null];
            }
            $order->update($attributes);

            // Annulée : les articles reviennent en stock ; réactivée : ils en ressortent.
            if ($status === 'cancelled') {
                $this->catalog->releaseOrder($order, $userId);
            } elseif ($previous === 'cancelled') {
                $this->catalog->reserveAgain($order, $userId);
            }

            // « Payée » : le reste à payer est enregistré comme un versement (moyen choisi par le client).
            if ($status === 'paid' && $order->balanceDue() > 0) {
                $this->payments->addPayment($order, $order->balanceDue(), PaymentService::methodFromText($order->payment_method), 'Commande marquée payée', $userId);
            }

            return $order->refresh();
        });
    }

    /**
     * Commandes de l'assistant restées « new » au-delà du délai de leur entreprise
     * (order_expiry_hours, 0 : jamais) : annulées (« non confirmée »), articles remis en stock
     * (mouvement order_expired), gérant prévenu par la cloche. Une commande avec une preuve de
     * paiement n'est jamais annulée : le gérant est prévenu une fois, à la place.
     *
     * @return array{expired: int, held: int}
     */
    public function expireStale(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['expired' => 0, 'held' => 0];

        $candidates = Order::query()
            ->with('business:id,order_expiry_hours')
            ->where('status', 'new')
            ->where('source', Order::SOURCE_AI)
            ->where('created_at', '<=', $now->copy()->subHour())
            ->orderBy('created_at')
            ->get();

        foreach ($candidates as $order) {
            $hours = (int) ($order->business?->order_expiry_hours ?? Business::DEFAULT_ORDER_EXPIRY_HOURS);
            if ($hours <= 0 || $order->created_at->gt($now->copy()->subHours($hours))) {
                continue;
            }

            try {
                if ($order->payment_proof_message_id !== null) {
                    if ($order->expiry_notified_at === null) {
                        $order->update(['expiry_notified_at' => $now]);
                        $this->notifications->notifyUnconfirmedPaidOrder($order, $hours);
                        $result['held']++;
                    }

                    continue;
                }

                $expired = DB::transaction(function () use ($order, $now) {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                    // Confirmée ou annulée entre-temps par le gérant : rien à faire.
                    if ($locked === null || $locked->status !== 'new') {
                        return false;
                    }

                    $locked->update(['status' => 'cancelled', 'cancelled_at' => $now, 'cancellation_reason' => Order::REASON_NOT_CONFIRMED]);
                    $this->catalog->releaseOrder($locked, null, StockMovement::ORDER_EXPIRED);

                    return true;
                });

                if ($expired) {
                    $this->notifications->notifyExpiredOrder($order->refresh(), $hours);
                    $result['expired']++;
                }
            } catch (Throwable $e) {
                // Une commande en erreur n'empêche pas les suivantes ; elle sera reprise au passage suivant.
                Log::error('OrderStatusService::expireStale : commande non traitée', ['order_id' => $order->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            }
        }

        return $result;
    }

    public const LABELS = [
        'new' => 'nouvelle',
        'confirmed' => 'confirmée',
        'paid' => 'payée',
        'delivered' => 'livrée',
        'cancelled' => 'annulée',
    ];
}
