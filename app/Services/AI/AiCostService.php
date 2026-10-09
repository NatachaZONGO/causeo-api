<?php

namespace App\Services\AI;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Coût des réponses de l'IA, calculé à partir des tokens enregistrés dans le
 * metadata des messages sortants (metadata.ai_usage) et du prix du modèle qui a
 * produit chaque réponse (services.anthropic.pricing.models). Changer un prix
 * recalcule l'historique. Les réponses d'un modèle sans prix connu sont comptées
 * à part (« prix inconnu »), jamais à zéro.
 */
class AiCostService
{
    public const UNKNOWN_PRICE = 'prix inconnu';

    private const TOKEN_KEYS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'];

    /**
     * Prix du modèle, par nom exact ou par alias suivi d'une date
     * (« claude-haiku-4-5-20251001 » → « claude-haiku-4-5 »). Null si inconnu.
     *
     * @return array{input: float, output: float, cache_write: float, cache_read: float}|null
     */
    public function priceFor(?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }

        $models = (array) config('services.anthropic.pricing.models', []);
        $alias = preg_replace('/-\d{8}$/', '', $model);

        return $models[$model] ?? $models[$alias] ?? null;
    }

    /**
     * Coût en dollars d'un ensemble de tokens produits par un modèle, null si
     * le prix du modèle est inconnu.
     *
     * @param  array<string, int|float|string|null>  $tokens
     */
    public function costUsd(?string $model, array $tokens): ?float
    {
        $price = $this->priceFor($model);

        if ($price === null) {
            return null;
        }

        $input = (float) $price['input'];

        return ((int) ($tokens['input_tokens'] ?? 0) * $input
            + (int) ($tokens['output_tokens'] ?? 0) * (float) $price['output']
            + (int) ($tokens['cache_creation_input_tokens'] ?? 0) * $input * (float) $price['cache_write']
            + (int) ($tokens['cache_read_input_tokens'] ?? 0) * $input * (float) $price['cache_read']
        ) / 1_000_000;
    }

    /**
     * @return array{usd_to_xof: float, models: array<string, array<string, float>>}
     */
    public function pricing(): array
    {
        return [
            'usd_to_xof' => $this->rate(),
            'models' => (array) config('services.anthropic.pricing.models', []),
        ];
    }

    /**
     * Coût par business pour un mois, du plus coûteux au moins coûteux, avec le total.
     *
     * @return array{totals: array<string, mixed>, businesses: list<array<string, mixed>>}
     */
    public function month(CarbonImmutable $month): array
    {
        $groups = $this->replies($month->startOfMonth(), $month->addMonth()->startOfMonth())
            ->join('businesses', 'businesses.id', '=', 'conversations.business_id')
            ->groupBy('businesses.id', 'businesses.name', 'businesses.plan', DB::raw($this->model()))
            ->selectRaw('businesses.id as business_id, businesses.name, businesses.plan, '.$this->model().' as model, '.$this->sums())
            ->get();

        $businesses = $groups
            ->groupBy('business_id')
            ->map(function ($rows) {
                $first = $rows->first();

                return ['business_id' => $first->business_id, 'name' => $first->name, 'plan' => $first->plan] + $this->figures($rows->all());
            })
            // Les plus coûteux d'abord ; ceux dont le coût est inconnu à la fin.
            ->sortByDesc(fn (array $business) => $business['cost_usd'] ?? -1)
            ->values()
            ->all();

        return ['totals' => $this->figures($groups->all()), 'businesses' => $businesses];
    }

    /**
     * Totaux des derniers mois, du plus ancien au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    public function history(CarbonImmutable $until, int $months = 6): array
    {
        $history = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $until->startOfMonth()->subMonths($i);
            $groups = $this->replies($month, $month->addMonth())
                ->groupBy(DB::raw($this->model()))
                ->selectRaw($this->model().' as model, '.$this->sums())
                ->get();

            $history[] = ['month' => $month->format('Y-m')] + $this->figures($groups->all());
        }

        return $history;
    }

    /**
     * Additionner des groupes (un par modèle) : coût des modèles au prix connu,
     * réponses des autres comptées à part.
     *
     * @param  array<int, object>  $groups
     * @return array<string, mixed>
     */
    private function figures(array $groups): array
    {
        $totals = array_fill_keys(['replies', 'calls', ...self::TOKEN_KEYS], 0);
        $byModel = [];
        $cost = 0.0;
        $pricedReplies = 0;

        foreach ($groups as $group) {
            $row = (array) $group;
            $model = $row['model'] ?? null;
            $replies = (int) $row['replies'];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }

            $modelCost = $this->costUsd($model, $row);
            if ($modelCost !== null) {
                $cost += $modelCost;
                $pricedReplies += $replies;
            }

            $key = $model ?: 'inconnu';
            $byModel[$key] ??= ['model' => $key, 'replies' => 0, 'cost_usd' => $modelCost === null ? null : 0.0];
            $byModel[$key]['replies'] += $replies;
            if ($modelCost !== null) {
                $byModel[$key]['cost_usd'] += $modelCost;
            }
        }

        $rate = $this->rate();
        $unpricedReplies = $totals['replies'] - $pricedReplies;

        return $totals + [
            'priced_replies' => $pricedReplies,
            'unpriced_replies' => $unpricedReplies,
            'cost_usd' => $pricedReplies > 0 ? round($cost, 4) : null,
            'cost_xof' => $pricedReplies > 0 ? round($cost * $rate) : null,
            // Moyenne sur les seules réponses dont le prix est connu.
            'average_cost_usd' => $pricedReplies > 0 ? round($cost / $pricedReplies, 6) : null,
            'average_cost_xof' => $pricedReplies > 0 ? round($cost * $rate / $pricedReplies, 2) : null,
            'price_status' => $unpricedReplies > 0 ? self::UNKNOWN_PRICE : null,
            'models' => array_values(array_map(fn (array $model) => [
                ...$model,
                'cost_usd' => $model['cost_usd'] === null ? null : round($model['cost_usd'], 4),
                'cost_xof' => $model['cost_usd'] === null ? null : round($model['cost_usd'] * $rate),
                'price_status' => $model['cost_usd'] === null ? self::UNKNOWN_PRICE : null,
            ], $byModel)),
        ];
    }

    /**
     * Réponses de l'IA pour lesquelles les tokens ont été enregistrés.
     */
    private function replies(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.direction', 'outbound')
            ->where('messages.sender_type', 'ai')
            ->whereNotNull('messages.metadata->ai_usage')
            ->where('messages.created_at', '>=', $from->utc())
            ->where('messages.created_at', '<', $to->utc());
    }

    private function sums(): string
    {
        $columns = ['count(*) as replies', 'coalesce(sum('.$this->usage('calls').'), 0) as calls'];

        foreach (self::TOKEN_KEYS as $key) {
            $columns[] = 'coalesce(sum('.$this->usage($key).'), 0) as '.$key;
        }

        return implode(', ', $columns);
    }

    private function usage(string $key): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "coalesce((messages.metadata->'ai_usage'->>'{$key}')::bigint, 0)"
            : "coalesce(json_extract(messages.metadata, '$.ai_usage.{$key}'), 0)";
    }

    private function model(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "(messages.metadata->'ai_usage'->>'model')"
            : "json_extract(messages.metadata, '$.ai_usage.model')";
    }

    private function rate(): float
    {
        return (float) config('services.anthropic.pricing.usd_to_xof', 600);
    }
}
