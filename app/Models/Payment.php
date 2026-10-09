<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement déclaré par un gérant (Orange Money, Moov Money), validé ou refusé
 * par un admin. La validation active ou prolonge l'abonnement une seule fois.
 */
class Payment extends Model
{
    use HasUuids;

    public const METHODS = ['orange_money', 'moov_money'];

    public const METHOD_LABELS = ['orange_money' => 'Orange Money', 'moov_money' => 'Moov Money'];

    public const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'plan_id',
        'subscription_id',
        'months',
        'amount',
        'currency',
        'method',
        'reference',
        'payer_phone',
        'proof_path',
        'status',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'period_start',
        'period_end',
    ];

    /**
     * Le chemin de la capture reste interne : elle se télécharge via l'admin.
     *
     * @var list<string>
     */
    protected $hidden = ['proof_path'];

    /**
     * @var list<string>
     */
    protected $appends = ['has_proof'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'amount' => 'integer',
            'reviewed_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function getHasProofAttribute(): bool
    {
        return ! empty($this->proof_path);
    }

    /**
     * Référence comparable : sans espaces superflus, en majuscules.
     */
    public static function normalizeReference(?string $reference): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $reference));
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
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
