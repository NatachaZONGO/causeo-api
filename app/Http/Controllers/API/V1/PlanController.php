<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * Formules publiques, dans l'ordre d'affichage (route publique, sans authentification).
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()->public()->get()->map(fn (Plan $plan) => [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_fcfa' => $plan->price_fcfa,
            'period_days' => $plan->period_days,
            'daily_price_fcfa' => $plan->dailyPrice(),
            'highlight_daily_price' => $plan->highlight_daily_price,
            'badge' => $plan->badge,
            'reply_limit' => $plan->reply_limit,
            'reply_limit_period' => $plan->reply_limit_period,
            'document_limit' => $plan->document_limit,
            'modules' => $plan->modules,
            'learning' => $plan->learning,
            'features' => $plan->features ?? [],
        ]);

        return response()->json(['plans' => $plans]);
    }
}
