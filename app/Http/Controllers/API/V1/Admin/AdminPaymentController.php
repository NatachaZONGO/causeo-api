<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPaymentController extends Controller
{
    /**
     * Paiements déclarés, les plus récents d'abord (filtre ?status=pending|approved|rejected).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(Payment::STATUSES)]]);

        $payments = Payment::query()
            ->with(['plan:id,slug,name', 'business:id,name,user_id', 'business.user:id,name,email'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return response()->json([
            'pending_count' => Payment::query()->where('status', 'pending')->count(),
            ...$payments->toArray(),
        ]);
    }

    /**
     * Télécharger la capture d'écran jointe au paiement (bucket privé).
     */
    public function proof(Payment $payment): StreamedResponse
    {
        $disk = Storage::disk('supabase_documents');

        abort_if(empty($payment->proof_path) || ! $disk->exists($payment->proof_path), 404, 'Aucune capture pour ce paiement.');

        return $disk->download($payment->proof_path);
    }

    /**
     * Valider : active ou prolonge l'abonnement, une seule fois.
     */
    public function approve(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        try {
            $payment = $payments->approve($payment, $request->user());
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json([
            'message' => 'Paiement validé.',
            'payment' => $payment,
        ]);
    }

    /**
     * Refuser avec un motif, communiqué au gérant.
     */
    public function reject(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $payment = $payments->reject($payment, $request->user(), $validated['reason']);
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json([
            'message' => 'Paiement refusé.',
            'payment' => $payment,
        ]);
    }
}
