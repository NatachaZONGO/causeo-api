<?php

namespace App\Models;

use App\Support\ActivityProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article du catalogue (module « catalog »). Ses variantes portent le prix éventuel
 * et le stock ; un article sans variantes en a une seule, aux options vides. Supprimer un
 * article l'archive (deleted_at) : il sort du catalogue et de l'assistant, son historique de
 * stock et ses lignes de commande restent.
 */
class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'name',
        'description',
        'category',
        'base_price',
        'is_active',
        'media_ids',
        'duration_minutes',
        'preparation_minutes',
        'extras',
        'sold_out_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'sold_out',
    ];

    /**
     * L'embedding (recherche de l'assistant) n'est jamais renvoyé par l'API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'embedding',
        // Chargé pour le profil d'activité (tracksStock), jamais renvoyé avec l'article.
        'business',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'float',
            'is_active' => 'boolean',
            'media_ids' => 'array',
            'duration_minutes' => 'integer',
            'preparation_minutes' => 'integer',
            'extras' => 'array',
            'sold_out_at' => 'datetime',
        ];
    }

    /**
     * Épuisé (restaurant) : marqué épuisé aujourd'hui, dans le fuseau du business, ou jusqu'à
     * réactivation manuelle si le business ne remet pas ses plats à disponible chaque matin.
     */
    public function isSoldOut(): bool
    {
        if ($this->sold_out_at === null) {
            return false;
        }

        $business = $this->business;

        if ($business === null || ! $business->catalog_daily_reset) {
            return true;
        }

        $timezone = $business->localTimezone();

        return $this->sold_out_at->copy()->timezone($timezone)->toDateString() === now($timezone)->toDateString();
    }

    public function getSoldOutAttribute(): bool
    {
        return $this->isSoldOut();
    }

    /**
     * Stock suivi en quantité : profil boutique seulement. Au restaurant et pour les prestations,
     * un stock_quantity resté d'un ancien profil est ignoré (disponibilité et sorties de stock).
     */
    public function tracksStock(): bool
    {
        $business = $this->business;

        return $business === null
            || ActivityProfile::definition($business->activityProfile())['tracking'] === ActivityProfile::TRACK_QUANTITY;
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        // chaperone : chaque variante connaît son article (prix effectif) sans requête de plus.
        return $this->hasMany(ProductVariant::class)->chaperone('product')->orderBy('created_at')->orderBy('id');
    }
}
