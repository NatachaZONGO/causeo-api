<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article du catalogue (module « catalog »). Ses variantes portent le prix éventuel
 * et le stock ; un article sans variantes en a une seule, aux options vides.
 */
class Product extends Model
{
    use HasFactory, HasUuids;

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
    ];

    /**
     * L'embedding (recherche de l'assistant) n'est jamais renvoyé par l'API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'embedding',
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
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        // chaperone : chaque variante connaît son article (prix effectif) sans requête de plus.
        return $this->hasMany(ProductVariant::class)->chaperone('product')->orderBy('created_at')->orderBy('id');
    }
}
