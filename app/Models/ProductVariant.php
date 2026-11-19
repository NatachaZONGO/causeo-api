<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Variante d'un article (taille, couleur…) : prix propre ou celui de l'article, et
 * stock (null : non suivi, toujours disponible). Une variante n'est jamais supprimée de la
 * base par l'application : elle est archivée (deleted_at) et garde son historique. Les options
 * sont uniques par article (options_key) et le SKU par entreprise, hors variantes archivées.
 */
class ProductVariant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /** Disponibilités communiquées au client : jamais le nombre exact de pièces. */
    public const AVAILABLE = 'available';

    public const LOW_STOCK = 'low_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    public const AVAILABILITY_LABELS = [
        self::AVAILABLE => 'disponible',
        self::LOW_STOCK => 'dernières pièces',
        self::OUT_OF_STOCK => 'en rupture',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'options',
        'price',
        'stock_quantity',
        'low_stock_threshold',
        'sku',
    ];

    /**
     * L'article n'est pas répété dans chaque variante (ni de boucle article → variantes → article).
     *
     * @var list<string>
     */
    protected $hidden = [
        'product',
        'options_key',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'effective_price',
        'availability',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price' => 'float',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Clé d'unicité des options et entreprise (unicité du SKU), tenues à jour à chaque enregistrement.
        static::saving(function (ProductVariant $variant): void {
            $variant->options_key = \App\Services\Catalog\CatalogService::optionsKey((array) ($variant->options ?? []));
            if ($variant->business_id === null && $variant->product_id !== null) {
                $variant->business_id = Product::withTrashed()->whereKey($variant->product_id)->value('business_id');
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Stock suivi : une quantité est enregistrée et le profil d'activité suit le stock (boutique). */
    public function tracksStock(): bool
    {
        return $this->stock_quantity !== null && ($this->product?->tracksStock() ?? true);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /** Prix de vente : celui de la variante, sinon celui de l'article. */
    public function getEffectivePriceAttribute(): ?float
    {
        return $this->price ?? $this->product?->base_price;
    }

    public function getAvailabilityAttribute(): string
    {
        // Plat épuisé aujourd'hui (restaurant) : toutes ses variantes le sont.
        if ($this->product?->sold_out_at !== null && $this->product->isSoldOut()) {
            return self::OUT_OF_STOCK;
        }

        // Hors boutique, un stock resté d'un ancien profil ne compte pas.
        if ($this->product !== null && ! $this->product->tracksStock()) {
            return self::AVAILABLE;
        }

        return self::availabilityFor($this->stock_quantity, $this->low_stock_threshold ?? 2);
    }

    public static function availabilityFor(?int $stock, int $threshold): string
    {
        return match (true) {
            $stock === null => self::AVAILABLE,
            $stock <= 0 => self::OUT_OF_STOCK,
            $stock <= $threshold => self::LOW_STOCK,
            default => self::AVAILABLE,
        };
    }

    /** « taille M, couleur rouge » ; vide pour un article sans variantes. */
    public function optionsLabel(): string
    {
        return implode(', ', array_map(
            fn (string $name, mixed $value) => "{$name} {$value}",
            array_keys($this->options ?? []),
            array_values($this->options ?? []),
        ));
    }
}
