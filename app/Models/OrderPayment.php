<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement sur une commande (espèces, Orange Money, Moov Money…). La somme des versements
 * donne orders.amount_paid et orders.payment_status (voir App\Services\Sales\PaymentService).
 */
class OrderPayment extends Model
{
    public const CASH = 'cash';

    public const ORANGE_MONEY = 'orange_money';

    public const MOOV_MONEY = 'moov_money';

    public const OTHER = 'other';

    public const METHODS = [self::CASH, self::ORANGE_MONEY, self::MOOV_MONEY, self::OTHER];

    public const METHOD_LABELS = [
        self::CASH => 'Espèces',
        self::ORANGE_MONEY => 'Orange Money',
        self::MOOV_MONEY => 'Moov Money',
        self::OTHER => 'Autre',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'order_id',
        'amount',
        'method',
        'paid_at',
        'note',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
