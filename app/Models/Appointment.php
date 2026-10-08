<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['requested', 'confirmed', 'declined', 'cancelled', 'completed'];

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
        'service',
        'requested_date',
        'requested_time',
        'location',
        'participants',
        'price',
        'notes',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_date' => 'date:Y-m-d',
            'participants' => 'integer',
            'price' => 'float',
        ];
    }

    /**
     * Référence courte communiquée au client (même format que les commandes).
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
