<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'customer_phone',
        'customer_name',
        'whatsapp_conversation_id',
        'is_active',
        'last_message_at',
        'last_read_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_message_at' => 'datetime',
            'last_read_at' => 'datetime',
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
     * @return HasMany<Message>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<Escalation>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    /**
     * Commandes passées dans cette conversation.
     *
     * @return HasMany<Order>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Le gérant a lu la conversation : ses messages entrants ne sont plus « non lus ».
     */
    public function markAsRead(): void
    {
        $this->forceFill(['last_read_at' => now()])->save();
    }

    /**
     * Le message le plus récent de la conversation (created_at, puis id en cas d'égalité).
     *
     * Pas de latestOfMany : il départage les égalités avec MAX(messages.id), qui n'existe
     * pas pour un uuid sous PostgreSQL (« function max(uuid) does not exist »). Une
     * sous-requête corrélée par message chargé, et une seule requête pour toute la page.
     *
     * @return HasOne<Message>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->where('messages.id', function ($query) {
            $query->select('latest.id')
                ->from('messages as latest')
                ->whereColumn('latest.conversation_id', 'messages.conversation_id')
                ->orderByDesc('latest.created_at')
                ->orderByDesc('latest.id')
                ->limit(1);
        });
    }
}
