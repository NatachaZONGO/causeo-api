<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Versements sur une commande ou une vente : chaque versement est une ligne de order_payments,
 * dont se déduisent orders.amount_paid et orders.payment_status (unpaid, partial, paid).
 * Phase 2 (crédits clients) : les ventes non soldées se retrouvent par payment_status et
 * customer_phone ; un remboursement sera un versement de plus.
 */
class PaymentService
{
    /**
     * Ajouter un versement. Refusé s'il dépasse le reste à encaisser, sur une commande annulée,
     * ou pour un montant nul.
     *
     * @throws InvalidArgumentException message destiné au gérant
     */
    public function addPayment(Order $order, float $amount, string $method, ?string $note = null, ?int $userId = null, ?Carbon $paidAt = null): OrderPayment
    {
        return DB::transaction(function () use ($order, $amount, $method, $note, $userId, $paidAt) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 2);

            if ($locked->status === 'cancelled') {
                throw new InvalidArgumentException('Cette commande est annulée : aucun versement ne peut y être ajouté.');
            }
            if (! in_array($method, OrderPayment::METHODS, true)) {
                throw new InvalidArgumentException('Moyen de paiement inconnu : espèces, Orange Money, Moov Money ou autre.');
            }
            if ($amount <= 0) {
                throw new InvalidArgumentException('Le montant du versement doit être supérieur à 0.');
            }

            $due = $locked->balanceDue();
            if ($amount > $due) {
                throw new InvalidArgumentException($due <= 0
                    ? 'Cette commande est déjà entièrement payée.'
                    : 'Le versement ('.self::money($amount).') dépasse le reste à payer ('.self::money($due).').');
            }

            $payment = OrderPayment::create([
                'business_id' => $locked->business_id,
                'order_id' => $locked->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => $paidAt ?? now(),
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : null,
                'user_id' => $userId,
            ]);

            $this->refresh($locked);
            $order->setRawAttributes($locked->getAttributes(), sync: true);

            return $payment;
        });
    }

    /**
     * Recalculer amount_paid, payment_status et paid_at à partir des versements.
     */
    public function refresh(Order $order): Order
    {
        $payments = $order->payments()->get(['amount', 'paid_at']);
        $paid = round((float) $payments->sum('amount'), 2);
        $total = round((float) $order->total_amount, 2);

        $status = match (true) {
            $paid >= $total && ($paid > 0 || $total <= 0) => Order::PAYMENT_PAID,
            $paid > 0 => Order::PAYMENT_PARTIAL,
            default => Order::PAYMENT_UNPAID,
        };

        $order->forceFill([
            'amount_paid' => $paid,
            'payment_status' => $status,
            // Soldée : date du dernier versement ; sinon, la date de paiement est celle du statut « paid ».
            'paid_at' => $status === Order::PAYMENT_PAID ? ($payments->max('paid_at') ?? $order->paid_at ?? now()) : ($order->status === 'paid' ? $order->paid_at : null),
        ])->save();

        return $order;
    }

    /** Moyen de paiement déduit du texte libre d'une commande de l'assistant (« Orange Money »…). */
    public static function methodFromText(?string $text): string
    {
        $text = mb_strtolower(\Illuminate\Support\Str::ascii((string) $text));

        return match (true) {
            str_contains($text, 'orange') => OrderPayment::ORANGE_MONEY,
            str_contains($text, 'moov') => OrderPayment::MOOV_MONEY,
            str_contains($text, 'espece') || str_contains($text, 'cash') || str_contains($text, 'livraison') => OrderPayment::CASH,
            default => OrderPayment::OTHER,
        };
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
