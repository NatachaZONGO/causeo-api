<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class AdminPlanController extends Controller
{
    /**
     * Toutes les formules actives, publiques ou non (Interne compris), dans l'ordre
     * d'affichage : ce sont les formules qu'un admin peut attribuer à un business.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (Plan $plan) => [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'is_public' => $plan->is_public,
            'price_fcfa' => $plan->price_fcfa,
            'period_days' => $plan->period_days,
        ]);

        return response()->json(['plans' => $plans]);
    }
}
