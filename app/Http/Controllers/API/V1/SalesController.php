<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Sales\SalesSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Résumé des ventes (bloc « Ventes » de l'accueil) : chiffre d'affaires, encaissé, reste à
 * encaisser, évolution et meilleurs articles. Consultable dès que le module Commandes est activé.
 */
class SalesController extends Controller
{
    public function summary(Request $request, Business $business, SalesSummaryService $sales): JsonResponse
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
        abort_unless($business->moduleEnabled('orders'), 403, "Le module Commandes n'est pas activé pour cette entreprise.");

        $data = $request->validate([
            'period' => ['nullable', Rule::in(SalesSummaryService::PERIODS)],
        ], [
            'period.in' => 'La période doit être day (jour), week (semaine) ou month (mois).',
        ]);

        return response()->json($sales->summary($business, $data['period'] ?? 'day'));
    }
}
