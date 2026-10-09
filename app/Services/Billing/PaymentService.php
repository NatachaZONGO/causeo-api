<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Paiements manuels (Orange Money, Moov Money) : déclaration par le gérant,
 * validation ou refus par un admin.
 */
class PaymentService
{
    /** Formules qu'un gérant peut payer. */
    public const PAYABLE_PLANS = [Plan::STARTER, Plan::PRO, Plan::BUSINESS];

    public function __construct(
        private readonly BillingService $billing,
    ) {
    }

    /**
     * Moyens de paiement proposés : seulement ceux dont le numéro est configuré.
     *
     * @return list<array{method: string, label: string, number: string, account_name: string}>
     */
    public function availableMethods(): array
    {
        $methods = [];

        foreach (Payment::METHODS as $method) {
            $number = config("services.payments.{$method}_number");

            if (! empty($number)) {
                $methods[] = [
                    'method' => $method,
                    'label' => Payment::METHOD_LABELS[$method],
                    'number' => (string) $number,
                    'account_name' => (string) config('services.payments.account_name'),
                ];
            }
        }

        return $methods;
    }

    /**
     * Déclarer un paiement. Le montant est calculé ici (prix XOF × nombre de mois).
     */
    public function declare(Business $business, User $user, Plan $plan, string $method, string $reference, int $months, ?string $payerPhone, ?UploadedFile $proof): Payment
    {
        $amount = $plan->priceIn(Currency::XOF)['amount'] * $months;

        $proofPath = $proof?->storeAs(
            "payments/{$business->id}",
            Str::uuid().'.'.$proof->getClientOriginalExtension(),
            'supabase_documents',
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'months' => $months,
            'amount' => $amount,
            'currency' => Currency::XOF,
            'method' => $method,
            'reference' => Payment::normalizeReference($reference),
            'payer_phone' => $payerPhone,
            'proof_path' => $proofPath ?: null,
            'status' => 'pending',
            'submitted_by' => $user->id,
        ]);

        $this->notify($business, Notification::ADMIN, "Paiement à valider : {$business->name}",
            "{$this->money($amount)} par ".Payment::METHOD_LABELS[$method]." pour la formule {$plan->name} ("
            .$this->months($months)."), référence {$payment->reference}. Déclaré par {$user->name}.",
            ['kind' => 'payment_submitted', 'payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * Valider un paiement : verrouille la ligne, active ou prolonge l'abonnement
     * une seule fois, puis prévient le gérant.
     *
     * @throws RuntimeException si le paiement n'est plus en attente
     */
    public function approve(Payment $payment, User $admin): Payment
    {
        $payment = DB::transaction(function () use ($payment, $admin) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Ce paiement a déjà été traité.');
            }

            $applied = $this->billing->applyPayment($locked->business, $locked->plan, $locked->months);

            $locked->update([
                'status' => 'approved',
                'subscription_id' => $applied['subscription']->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'period_start' => $applied['period_start'],
                'period_end' => $applied['period_end'],
            ]);

            return $locked->fresh(['plan', 'business']);
        });

        $this->notify($payment->business, Notification::OWNER, 'Paiement validé',
            "Votre paiement de {$this->money($payment->amount)} est validé : formule {$payment->plan->name} active jusqu'au "
            .$payment->period_end->setTimezone(UsageService::TIMEZONE)->format('d/m/Y').'.',
            ['kind' => 'payment_approved', 'payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * Refuser un paiement avec un motif, puis prévenir le gérant.
     *
     * @throws RuntimeException si le paiement n'est plus en attente
     */
    public function reject(Payment $payment, User $admin, string $reason): Payment
    {
        $payment = DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Ce paiement a déjà été traité.');
            }

            $locked->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked->fresh(['plan', 'business']);
        });

        $this->notify($payment->business, Notification::OWNER, 'Paiement refusé',
            "Votre paiement de {$this->money($payment->amount)} (référence {$payment->reference}) a été refusé : {$reason} "
            .'Vous pouvez déclarer un nouveau paiement depuis la page Abonnement.',
            ['kind' => 'payment_rejected', 'payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(Business $business, string $audience, string $title, string $body, array $data): void
    {
        try {
            Notification::create([
                'business_id' => $business->id,
                'audience' => $audience,
                'type' => 'billing',
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas annuler le paiement.
            Log::error('PaymentService: notification impossible', [
                'business_id' => $business->id,
                'kind' => $data['kind'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }

    private function months(int $months): string
    {
        return $months > 1 ? "{$months} mois" : '1 mois';
    }
}
