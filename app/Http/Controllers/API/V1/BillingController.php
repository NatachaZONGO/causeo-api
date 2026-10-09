<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Billing\Currency;
use App\Services\Billing\UsageService;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    /**
     * Formule et statut effectifs du business (calculés à partir des dates), avec
     * le prix dans la devise du business. Les paiements ne sont possibles qu'en XOF.
     */
    public function show(Business $business, UsageService $usage): JsonResponse
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');

        $state = $business->billingState();
        $currency = $business->currency();
        $payable = Currency::isPayable($currency);

        $billing = $state->toArray();
        $billing['currency'] = $currency;
        $billing['plan']['price'] = $state->plan->priceIn($currency);
        $billing['payment'] = [
            'available' => $payable,
            'currency' => Currency::XOF,
            'methods' => $payable ? ['orange_money', 'moov_money'] : [],
            'message' => $payable ? null : 'Bientôt disponible',
        ];
        // Réponses automatiques du jour (Gratuit) ou de la période (formules payantes).
        $billing['usage'] = $usage->usage($business, $state)->toArray();

        return response()->json(['billing' => $billing]);
    }
}
