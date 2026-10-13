<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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

    /**
     * Durée des essais commencés avant le passage aux unités (30 jours, limite du Pro).
     * Les nouveaux essais suivent config/billing.php (TRIAL_DAYS, TRIAL_UNITS).
     */
    public const LEGACY_TRIAL_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'plan_id',
        'status',
        'trial_started_at',
        'trial_ends_at',
        'trial_unit_limit',
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
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'trial_unit_limit' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Début de l'essai (déduit de sa fin pour un essai de l'ancienne offre).
     */
    public function trialStartedAt(): ?CarbonImmutable
    {
        if ($this->trial_started_at !== null) {
            return CarbonImmutable::instance($this->trial_started_at);
        }

        return $this->trial_ends_at !== null
            ? CarbonImmutable::instance($this->trial_ends_at)->subDays(self::LEGACY_TRIAL_DAYS)
            : null;
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
