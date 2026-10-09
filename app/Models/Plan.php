<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory, HasUuids;

    public const FREE = 'free';

    public const STARTER = 'starter';

    public const PRO = 'pro';

    public const BUSINESS = 'business';

    public const INTERNAL = 'internal';

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
