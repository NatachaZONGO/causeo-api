<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\Currency;
use App\Services\Billing\PaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    /**
     * Historique des paiements déclarés par l'entreprise.
     */
    public function index(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $payments = Payment::query()
            ->where('business_id', $business->id)
            ->with('plan:id,slug,name')
            ->latest()
            ->paginate(20);

        return response()->json($payments);
    }

    /**
     * Déclarer un paiement Orange Money ou Moov Money. Le montant est calculé
     * par le serveur ; un admin valide ensuite la transaction.
     */
    public function store(Request $request, Business $business, PaymentService $payments): JsonResponse
    {
        $this->checkOwnership($business);

        abort_unless(Currency::isPayable($business->currency()), 422, 'Le paiement dans votre devise sera bientôt disponible.');

        $configured = array_column($payments->availableMethods(), 'method');
        abort_if($configured === [], 422, 'Le paiement en ligne sera bientôt disponible.');

        $request->merge(['reference' => Payment::normalizeReference($request->input('reference'))]);

        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in(PaymentService::PAYABLE_PLANS)],
            'method' => ['required', 'string', Rule::in($configured)],
            'reference' => [
                'required', 'string', 'min:4', 'max:100',
                Rule::unique('payments', 'reference')->where('method', $request->input('method')),
            ],
            'months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'payer_phone' => ['nullable', 'string', 'max:30'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'reference.unique' => 'Cette référence de transaction a déjà été déclarée.',
        ]);

        $plan = Plan::bySlug($validated['plan']);
        abort_if($plan->priceIn(Currency::XOF)['amount'] <= 0, 422, 'Cette formule n\'a pas de prix en FCFA.');

        try {
            $payment = $payments->declare(
                $business,
                $request->user(),
                $plan,
                $validated['method'],
                $validated['reference'],
                (int) ($validated['months'] ?? 1),
                $validated['payer_phone'] ?? null,
                $request->file('proof'),
            );
        } catch (UniqueConstraintViolationException) {
            // Deux déclarations simultanées de la même référence.
            throw ValidationException::withMessages([
                'reference' => 'Cette référence de transaction a déjà été déclarée.',
            ]);
        }

        return response()->json([
            'message' => 'Paiement déclaré : il sera vérifié et validé rapidement.',
            'payment' => $payment->load('plan:id,slug,name'),
        ], 201);
    }

    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
