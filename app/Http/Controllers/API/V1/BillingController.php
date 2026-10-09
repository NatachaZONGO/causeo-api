<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    /**
     * Formule et statut effectifs du business (calculés à partir des dates).
     */
    public function show(Business $business): JsonResponse
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');

        return response()->json([
            'billing' => $business->billingState()->toArray(),
        ]);
    }
}
