<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement de stock d'une variante : + entrée, - sortie. Un mouvement ne change
 * jamais : pas de updated_at. balance_after : stock de la variante après le mouvement.
 * Une variante supprimée de la base laisse ses mouvements (variant_id à null).
 */
class StockMovement extends Model
{
    public const ORDER = 'order';

    public const ORDER_CANCELLED = 'order_cancelled';

    public const MANUAL = 'manual';

    public const IMPORT = 'import';

    /** Réception de marchandise (entrée). */
    public const RESTOCK = 'restock';

    /** Inventaire : le stock compté remplace le stock enregistré. */
    public const ADJUSTMENT = 'adjustment';

    /** Casse, vol, perte (sortie). */
    public const LOSS = 'loss';

    /** Retour d'un client (entrée). */
    public const RETURN = 'return';

    /** Commande de l'assistant non confirmée à temps : ses articles reviennent en stock. */
    public const ORDER_EXPIRED = 'order_expired';

    /** Vente au comptoir (sortie). */
    public const COUNTER_SALE = 'counter_sale';

    public const REASONS = [
        self::ORDER, self::ORDER_CANCELLED, self::MANUAL, self::IMPORT, self::RESTOCK,
        self::ADJUSTMENT, self::LOSS, self::RETURN, self::ORDER_EXPIRED, self::COUNTER_SALE,
    ];

    /** Motifs saisis par le gérant avec POST variants/{id}/movements. */
    public const OWNER_REASONS = [self::RESTOCK, self::ADJUSTMENT, self::LOSS, self::RETURN];

    public const LABELS = [
        self::ORDER => 'Commande',
        self::ORDER_CANCELLED => 'Commande annulée',
        self::MANUAL => 'Modification manuelle',
        self::IMPORT => 'Import',
        self::RESTOCK => 'Réception',
        self::ADJUSTMENT => 'Inventaire',
        self::LOSS => 'Perte ou casse',
        self::RETURN => 'Retour client',
        self::ORDER_EXPIRED => 'Commande non confirmée',
        self::COUNTER_SALE => 'Vente au comptoir',
    ];

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'variant_id',
        'quantity',
        'reason',
        'order_id',
        'user_id',
        'note',
        'unit_cost',
        'balance_after',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'float',
            'balance_after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
