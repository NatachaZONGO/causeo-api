<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AiCostService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiCostController extends Controller
{
    /**
     * Coût des réponses de l'IA : coût moyen par réponse, coût par business pour
     * le mois demandé (?month=2026-10, mois en cours par défaut) et les 6 derniers mois.
     */
    public function index(Request $request, AiCostService $costs): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $validated['month'])
            : CarbonImmutable::now()->startOfMonth();

        return response()->json([
            'month' => $month->format('Y-m'),
            // Modèle utilisé pour les nouvelles réponses, et prix connus par modèle.
            'current_model' => config('services.anthropic.model'),
            'pricing' => $costs->pricing(),
            ...$costs->month($month),
            'history' => $costs->history($month),
        ]);
    }
}
