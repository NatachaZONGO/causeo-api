<?php

namespace App\Services\AI;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Coût des réponses de l'IA, calculé à partir des tokens enregistrés dans le
 * metadata des messages sortants (metadata.ai_usage) et du prix du modèle qui a
 * produit chaque réponse (services.anthropic.pricing.models). Changer un prix
 * recalcule l'historique. Les réponses d'un modèle sans prix connu sont comptées
 * à part (« prix inconnu »), jamais à zéro. La transcription des messages vocaux
 * (metadata.ai_usage.transcription, Gemini) s'ajoute au coût, au prix de
 * services.gemini.pricing.models.
 */
class AiCostService
{
    public const UNKNOWN_PRICE = 'prix inconnu';

    private const TOKEN_KEYS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'];

    private const TRANSCRIPTION_TOKEN_KEYS = ['input_audio_tokens', 'input_text_tokens', 'output_tokens'];

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
     * Coût en dollars d'une transcription (tokens Gemini), null si le prix du modèle est inconnu.
     *
     * @param  array<string, int|float|string|null>  $tokens
     */
    public function transcriptionCostUsd(?string $model, array $tokens): ?float
    {
        $price = $model ? (config('services.gemini.pricing.models', [])[$model] ?? null) : null;

        if ($price === null) {
            return null;
        }

        return ((int) ($tokens['input_audio_tokens'] ?? 0) * (float) $price['audio_input']
            + (int) ($tokens['input_text_tokens'] ?? 0) * (float) $price['input']
            + (int) ($tokens['output_tokens'] ?? 0) * (float) $price['output']
        ) / 1_000_000;
    }

    /**
     * @return array{usd_to_xof: float, models: array<string, array<string, float>>, transcription_models: array<string, array<string, float>>}
     */
    public function pricing(): array
    {
        return [
            'usd_to_xof' => $this->rate(),
            'models' => (array) config('services.anthropic.pricing.models', []),
            'transcription_models' => (array) config('services.gemini.pricing.models', []),
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
            ->groupBy('businesses.id', 'businesses.name', DB::raw($this->model()))
            ->selectRaw('businesses.id as business_id, businesses.name, '.$this->model().' as model, '.$this->sums())
            ->get();

        $transcriptions = $this->transcriptions($month->startOfMonth(), $month->addMonth()->startOfMonth())
            ->join('businesses', 'businesses.id', '=', 'conversations.business_id')
            ->groupBy('businesses.id', 'businesses.name', DB::raw($this->transcriptionModel()))
            ->selectRaw('businesses.id as business_id, businesses.name, '.$this->transcriptionModel().' as model, '.$this->transcriptionSums())
            ->get();

        // Formule effective (calculée à partir des dates), pas la colonne businesses.plan resynchronisée toutes les heures.
        $states = Business::query()
            ->with('subscription.plan')
            ->whereIn('id', $groups->pluck('business_id')->merge($transcriptions->pluck('business_id'))->unique())
            ->get()
            ->mapWithKeys(fn (Business $business) => [$business->id => $business->billingState()]);

        $transcriptionsByBusiness = $transcriptions->groupBy('business_id');

        $businesses = $groups
            ->concat($transcriptions->map(fn ($row) => (object) ['business_id' => $row->business_id, 'name' => $row->name, 'model' => null, 'replies' => 0]))
            ->groupBy('business_id')
            ->map(function ($rows) use ($states, $transcriptionsByBusiness) {
                $first = $rows->first();
                $plan = $states->get($first->business_id)?->plan;
                $replies = $rows->filter(fn ($row) => $row->model !== null || (int) $row->replies > 0)->all();

                return [
                    'business_id' => $first->business_id,
                    'name' => $first->name,
                    'plan' => $plan?->slug,
                    'plan_name' => $plan?->name,
                ] + $this->figures($replies, $transcriptionsByBusiness->get($first->business_id, collect())->all());
            })
            // Les plus coûteux d'abord ; ceux dont le coût est inconnu à la fin.
            ->sortByDesc(fn (array $business) => $business['cost_usd'] ?? -1)
            ->values()
            ->all();

        return ['totals' => $this->figures($groups->all(), $transcriptions->all()), 'businesses' => $businesses];
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

            $transcriptions = $this->transcriptions($month, $month->addMonth())
                ->groupBy(DB::raw($this->transcriptionModel()))
                ->selectRaw($this->transcriptionModel().' as model, '.$this->transcriptionSums())
                ->get();

            $history[] = ['month' => $month->format('Y-m')] + $this->figures($groups->all(), $transcriptions->all());
        }

        return $history;
    }

    /**
     * Additionner des groupes (un par modèle) : coût des modèles au prix connu,
     * réponses des autres comptées à part ; puis les transcriptions des vocaux.
     *
     * @param  array<int, object>  $groups
     * @param  array<int, object>  $transcriptionGroups
     * @return array<string, mixed>
     */
    private function figures(array $groups, array $transcriptionGroups = []): array
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

        // Transcriptions des messages vocaux (Gemini), ajoutées au coût total.
        $transcriptions = 0;
        $transcriptionCost = 0.0;
        $unpricedTranscriptions = 0;
        foreach ($transcriptionGroups as $group) {
            $row = (array) $group;
            $count = (int) $row['transcriptions'];
            $transcriptions += $count;
            $modelCost = $this->transcriptionCostUsd($row['model'] ?? null, $row);

            $key = ($row['model'] ?? null) ?: 'inconnu';
            $byModel["transcription:{$key}"] ??= ['model' => $key, 'kind' => 'transcription', 'replies' => 0, 'cost_usd' => $modelCost === null ? null : 0.0];
            $byModel["transcription:{$key}"]['replies'] += $count;

            if ($modelCost === null) {
                $unpricedTranscriptions += $count;
            } else {
                $transcriptionCost += $modelCost;
                $byModel["transcription:{$key}"]['cost_usd'] += $modelCost;
            }
        }

        $hasCost = $pricedReplies > 0 || $transcriptions > $unpricedTranscriptions;
        $cost += $transcriptionCost;

        return $totals + [
            'priced_replies' => $pricedReplies,
            'unpriced_replies' => $unpricedReplies,
            'transcriptions' => $transcriptions,
            'transcription_cost_usd' => $transcriptions > $unpricedTranscriptions ? round($transcriptionCost, 4) : null,
            'transcription_cost_xof' => $transcriptions > $unpricedTranscriptions ? round($transcriptionCost * $rate) : null,
            'cost_usd' => $hasCost ? round($cost, 4) : null,
            'cost_xof' => $hasCost ? round($cost * $rate) : null,
            // Moyenne sur les seules réponses dont le prix est connu (transcriptions comprises).
            'average_cost_usd' => $pricedReplies > 0 ? round($cost / $pricedReplies, 6) : null,
            'average_cost_xof' => $pricedReplies > 0 ? round($cost * $rate / $pricedReplies, 2) : null,
            'price_status' => $unpricedReplies + $unpricedTranscriptions > 0 ? self::UNKNOWN_PRICE : null,
            'models' => array_values(array_map(fn (array $model) => [
                'kind' => 'reply',
                ...$model,
                'cost_usd' => $model['cost_usd'] === null ? null : round($model['cost_usd'], 4),
                'cost_xof' => $model['cost_usd'] === null ? null : round($model['cost_usd'] * $rate),
                'price_status' => $model['cost_usd'] === null ? self::UNKNOWN_PRICE : null,
            ], $byModel)),
        ];
    }

    /**
     * Réponses de l'IA pour lesquelles les tokens de Claude ont été enregistrés (une
     * réponse à un vocal transmis au gérant n'a que la transcription : pas comptée ici).
     */
    private function replies(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->aiMessages($from, $to)->whereNotNull('messages.metadata->ai_usage->model');
    }

    /**
     * Réponses à un message vocal, avec les tokens de sa transcription.
     */
    private function transcriptions(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->aiMessages($from, $to)->whereNotNull('messages.metadata->ai_usage->transcription');
    }

    private function aiMessages(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.direction', 'outbound')
            ->where('messages.sender_type', 'ai')
            ->where('messages.created_at', '>=', $from->utc())
            ->where('messages.created_at', '<', $to->utc());
    }

    private function transcriptionSums(): string
    {
        $columns = ['count(*) as transcriptions'];

        foreach (self::TRANSCRIPTION_TOKEN_KEYS as $key) {
            $columns[] = 'coalesce(sum('.$this->usage("transcription.{$key}").'), 0) as '.$key;
        }

        return implode(', ', $columns);
    }

    private function transcriptionModel(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "(messages.metadata->'ai_usage'->'transcription'->>'model')"
            : "json_extract(messages.metadata, '$.ai_usage.transcription.model')";
    }

    private function sums(): string
    {
        $columns = ['count(*) as replies', 'coalesce(sum('.$this->usage('calls').'), 0) as calls'];

        foreach (self::TOKEN_KEYS as $key) {
            $columns[] = 'coalesce(sum('.$this->usage($key).'), 0) as '.$key;
        }

        return implode(', ', $columns);
    }

    /** $key : clé de ai_usage, éventuellement imbriquée (« transcription.output_tokens »). */
    private function usage(string $key): string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return "coalesce(json_extract(messages.metadata, '$.ai_usage.{$key}'), 0)";
        }

        $path = '{ai_usage,'.str_replace('.', ',', $key).'}';

        return "coalesce((messages.metadata#>>'{$path}')::bigint, 0)";
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
