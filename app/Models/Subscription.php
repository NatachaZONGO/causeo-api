<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement d'un business (une ligne par business). Le statut stocké n'est
 * qu'une photo : le statut effectif se calcule à partir des dates, voir
 * BillingService::state().
 */
class Subscription extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['trialing', 'active', 'grace', 'expired', 'cancelled'];

    /** Jours pendant lesquels une formule payante échue reste utilisable. */
    public const GRACE_DAYS = 3;

    /** Durée de l'essai Pro offert à la création d'un business. */
    public const TRIAL_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'ended_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    /**
     * Abonnements qui donnent encore accès à leur formule à l'instant présent : essai
     * non échu, période en cours ou en grâce, ou formule sans échéance (Interne).
     * Mêmes règles que BillingService::state(), traduites en SQL pour filtrer.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $query) use ($now) {
            $query
                ->where(fn (Builder $trial) => $trial
                    ->where('status', 'trialing')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', $now))
                ->orWhere(fn (Builder $period) => $period
                    ->whereNotIn('status', ['trialing', 'expired', 'cancelled'])
                    ->where(fn (Builder $end) => $end
                        ->whereNull('current_period_end')
                        ->orWhere('current_period_end', '>', $now->copy()->subDays(self::GRACE_DAYS))));
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
