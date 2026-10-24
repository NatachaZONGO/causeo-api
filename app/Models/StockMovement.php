<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement de stock d'une variante : + entrée, - sortie. Un mouvement ne change
 * jamais : pas de updated_at.
 */
class StockMovement extends Model
{
    public const ORDER = 'order';

    public const ORDER_CANCELLED = 'order_cancelled';

    public const MANUAL = 'manual';

    public const IMPORT = 'import';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'variant_id',
        'quantity',
        'reason',
        'order_id',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
