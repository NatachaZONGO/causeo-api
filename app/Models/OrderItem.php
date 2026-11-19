<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de commande : copie figée du nom, des options et des prix au moment de la vente.
 * catalog_unit_price : prix du catalogue (null hors catalogue) ; unit_price : prix pratiqué.
 */
class OrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'position',
        'name',
        'options',
        'quantity',
        'catalog_unit_price',
        'unit_price',
        'extras',
        'line_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'catalog_unit_price' => 'float',
            'unit_price' => 'float',
            'extras' => 'array',
            'line_total' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Ligne telle que l'API la renvoie dans order.items : le format historique de orders.items,
     * plus le prix du catalogue, le total de la ligne et l'article (s'il n'est pas archivé).
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'name' => $this->name,
            'options' => $this->options,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'catalog_unit_price' => $this->catalog_unit_price,
            'line_total' => $this->line_total,
        ]
            + ($this->variant_id !== null ? ['variant_id' => $this->variant_id] : [])
            + ($this->extras ? ['extras' => $this->extras] : [])
            + ($this->product_id !== null && $this->relationLoaded('product') && $this->product !== null && ! $this->product->trashed()
                ? ['product_id' => $this->product_id]
                : []);
    }
}
