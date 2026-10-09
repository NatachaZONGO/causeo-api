<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /**
     * Formules publiques, dans l'ordre d'affichage (route publique, sans authentification).
     * ?currency=XOF|EUR|USD choisit la devise de « price » (XOF par défaut).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['nullable', Rule::in(Currency::SUPPORTED)],
        ], [
            'currency.in' => 'Devise non prise en charge. Devises disponibles : '.implode(', ', Currency::SUPPORTED).'.',
        ]);

        $currency = $data['currency'] ?? Currency::XOF;

        $plans = Plan::query()->public()->with('prices')->get()->map(fn (Plan $plan) => [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->priceIn($currency),
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

        return response()->json([
            'currency' => $currency,
            'payable' => Currency::isPayable($currency),
            'plans' => $plans,
        ]);
    }
}
