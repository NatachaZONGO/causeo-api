<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statut WhatsApp publié par le gérant et enregistré dans Causeo : un article du catalogue, ou une
 * image importée et sa légende. Actif pendant LIFETIME_HOURS heures après sa publication.
 */
class StatusPost extends Model
{
    use HasUuids;

    public const LIFETIME_HOURS = 24;

    /** Photos envoyées au client qui répond alors que plusieurs statuts sont actifs, au plus. */
    public const MAX_CHOICES = 4;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'product_id',
        'media_id',
        'caption',
        'posted_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Statuts encore visibles (moins de 24 h), le plus récent d'abord.
     *
     * @param  Builder<StatusPost>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now())->where('posted_at', '<=', now())->orderByDesc('posted_at');
    }

    /** Nom montré au client : l'article, sinon la légende, sinon « photo du statut ». */
    public function label(): string
    {
        return $this->product->name ?? (trim((string) $this->caption) !== '' ? trim((string) $this->caption) : 'photo du statut');
    }

    /** Photo à envoyer au client : l'image importée, sinon la première photo de l'article. */
    public function photoId(): ?string
    {
        return $this->media_id ?? ((array) ($this->product->media_ids ?? []))[0] ?? null;
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<BusinessMedia, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(BusinessMedia::class);
    }
}
