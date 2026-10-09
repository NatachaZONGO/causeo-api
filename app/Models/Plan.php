<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, HasUuids;

    public const FREE = 'free';

    public const STARTER = 'starter';

    public const PRO = 'pro';

    public const BUSINESS = 'business';

    public const INTERNAL = 'internal';

    /** Temps qu'un gérant passerait à rédiger une réponse lui-même (temps économisé affiché). */
    public const MINUTES_PER_REPLY = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_fcfa',
        'period_days',
        'reply_limit',
        'reply_limit_period',
        'document_limit',
        'modules',
        'learning',
        'features',
        'badge',
        'highlight_daily_price',
        'is_public',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_fcfa' => 'integer',
            'period_days' => 'integer',
            'reply_limit' => 'integer',
            'document_limit' => 'integer',
            'modules' => 'array',
            'learning' => 'boolean',
            'features' => 'array',
            'highlight_daily_price' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Formules proposées aux clients (la formule Interne n'est pas publique).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true)->orderBy('sort_order');
    }

    public static function bySlug(string $slug): self
    {
        return static::query()->where('slug', $slug)->firstOrFail();
    }

    public function allowsModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }

    public function isPaid(): bool
    {
        return $this->price_fcfa > 0;
    }

    /**
     * @return HasMany<PlanPrice>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * Prix dans une devise : montant, période et prix journalier. Une formule sans
     * prix dans cette devise (Gratuit, Interne) vaut 0 sans échéance.
     *
     * @return array{currency: string, amount: int, period_days: ?int, daily_amount: ?float}
     */
    public function priceIn(string $currency): array
    {
        $price = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('currency', $currency)
            : $this->prices()->where('currency', $currency)->first();

        if ($price === null) {
            return ['currency' => $currency, 'amount' => 0, 'period_days' => null, 'daily_amount' => null];
        }

        return [
            'currency' => $currency,
            'amount' => $price->amount,
            'period_days' => $price->period_days,
            'daily_amount' => round($price->amount / $price->period_days, 2),
        ];
    }

    /**
     * Prix par jour (exact, arrondi au centime de franc), ou null sans échéance.
     */
    public function dailyPrice(): ?float
    {
        if (! $this->isPaid() || ! $this->period_days) {
            return null;
        }

        return round($this->price_fcfa / $this->period_days, 2);
    }
}
