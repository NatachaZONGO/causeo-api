<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client d'une entreprise : un numéro de téléphone normalisé (chiffres avec l'indicatif, voir
 * App\Support\PhoneNumber), qu'il ait écrit sur WhatsApp ou acheté au comptoir. Les montants et le
 * dernier message se calculent à la lecture (App\Services\Customers\CustomerListQuery).
 */
class Customer extends Model
{
    use HasUuids;

    /** Longueur du nom choisi par le commerçant. */
    public const NAME_MAX_LENGTH = 100;

    public const NOTE_MAX_LENGTH = 2000;

    /** Origine : a écrit sur WhatsApp, acheté au comptoir (ou saisi à la main), ou les deux. */
    public const ORIGIN_WHATSAPP = 'whatsapp';

    public const ORIGIN_COUNTER = 'counter';

    public const ORIGIN_BOTH = 'both';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'phone',
        'whatsapp_name',
        'name',
        'note',
        'first_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * Nom affiché : celui du commerçant, sinon celui de WhatsApp, sinon « Client 1234 ».
     */
    public function displayName(): string
    {
        foreach ([$this->name, $this->whatsapp_name] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                return $name;
            }
        }

        return 'Client '.substr((string) $this->phone, -4);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
