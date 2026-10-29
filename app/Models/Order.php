<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['new', 'confirmed', 'paid', 'delivered', 'cancelled'];

    /** dine_in : sur place (restaurant). */
    public const FULFILLMENT_TYPES = ['delivery', 'pickup', 'shipping', 'dine_in'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'conversation_id',
        'customer_phone',
        'customer_name',
        'items',
        'total_amount',
        'delivery_city',
        'delivery_address',
        'payment_method',
        'fulfillment_type',
        'pickup_time',
        'status',
        'notes',
        // Message WhatsApp contenant la capture du paiement envoyée par le client.
        'payment_proof_message_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_amount' => 'float',
        ];
    }

    /**
     * Référence courte communiquée au client.
     */
    public function reference(): string
    {
        return strtoupper(substr((string) $this->id, -6));
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
