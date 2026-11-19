<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['new', 'confirmed', 'paid', 'delivered', 'cancelled'];

    /**
     * Passages de statut autorisés (un statut identique est toujours accepté, sans effet).
     * Livrée → annulée : retour de la marchandise. Annulée → nouvelle ou confirmée : réactivation
     * (les articles ressortent du stock).
     */
    public const TRANSITIONS = [
        'new' => ['confirmed', 'paid', 'delivered', 'cancelled'],
        'confirmed' => ['paid', 'delivered', 'cancelled'],
        'paid' => ['delivered', 'cancelled'],
        'delivered' => ['cancelled'],
        'cancelled' => ['new', 'confirmed'],
    ];

    /** Date enregistrée à l'arrivée dans chaque statut. */
    public const STATUS_DATES = [
        'confirmed' => 'confirmed_at',
        'paid' => 'paid_at',
        'delivered' => 'delivered_at',
        'cancelled' => 'cancelled_at',
    ];

    /** dine_in : sur place (restaurant). */
    public const FULFILLMENT_TYPES = ['delivery', 'pickup', 'shipping', 'dine_in'];

    /** Origine : l'assistant WhatsApp, une vente au comptoir, une commande saisie par le gérant. */
    public const SOURCE_AI = 'whatsapp_ai';

    public const SOURCE_COUNTER = 'counter';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [self::SOURCE_AI, self::SOURCE_COUNTER, self::SOURCE_MANUAL];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_STATUSES = [self::PAYMENT_UNPAID, self::PAYMENT_PARTIAL, self::PAYMENT_PAID];

    /** Motif d'une commande de l'assistant annulée faute de confirmation (orders:expire). */
    public const REASON_NOT_CONFIRMED = 'non confirmée';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => self::SOURCE_AI,
        'amount_paid' => 0,
        'payment_status' => self::PAYMENT_UNPAID,
    ];

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
        'source',
        'amount_paid',
        'payment_status',
        'confirmed_at',
        'paid_at',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
        'expiry_notified_at',
        'created_by',
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
            'amount_paid' => 'float',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    /**
     * Référence courte communiquée au client.
     */
    public function reference(): string
    {
        return strtoupper(substr((string) $this->id, -6));
    }

    public function canMoveTo(string $status): bool
    {
        return $status === $this->status || in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Reste à encaisser (jamais négatif). */
    public function balanceDue(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->amount_paid, 2));
    }

    /**
     * Lignes de la commande : order_items, ou la copie JSON pour une commande sans lignes en table.
     *
     * @return list<array<string, mixed>>
     */
    public function lineItems(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        if ($lines->isNotEmpty()) {
            return $lines->map(fn (OrderItem $line) => $line->toApiArray())->values()->all();
        }

        return array_values((array) $this->items);
    }

    /**
     * Réponse de l'API : items lus depuis order_items (la colonne JSON n'est pas modifiée).
     */
    public function presentLines(): static
    {
        $this->setRawAttributes(array_merge($this->getAttributes(), [
            'items' => json_encode($this->lineItems(), JSON_UNESCAPED_UNICODE),
        ]), sync: false);
        $this->makeHidden('lines');

        return $this;
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

    /**
     * Lignes en table (« items » reste le nom de la colonne JSON de secours).
     *
     * @return HasMany<OrderItem, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('paid_at')->orderBy('id');
    }
}
