<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    /** Fin d'une pause de l'assistant « jusqu'à réactivation manuelle ». */
    public const INDEFINITE_PAUSE = '9999-12-31 23:59:59';

    /** Statut d'un message reçu pendant la pause de l'assistant : enregistré, sans réponse. */
    public const PAUSED_MESSAGE_STATUS = 'ai_paused';

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
        'ai_paused_until',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'ai_paused',
        'ai_paused_indefinitely',
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
            'ai_paused_until' => 'datetime',
        ];
    }

    /**
     * L'assistant est-il en pause dans cette conversation (le gérant a pris la main) ?
     */
    public function isAiPaused(): bool
    {
        return $this->ai_paused_until !== null && $this->ai_paused_until->isFuture();
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function aiPaused(): Attribute
    {
        return Attribute::get(fn () => $this->isAiPaused());
    }

    /**
     * Pause jusqu'à réactivation manuelle (le gérant l'a choisie, ou c'est son réglage).
     *
     * @return Attribute<bool, never>
     */
    protected function aiPausedIndefinitely(): Attribute
    {
        return Attribute::get(fn () => $this->ai_paused_until !== null && $this->ai_paused_until->year >= 9999);
    }

    /**
     * Mettre l'assistant en pause jusqu'à $until, ou jusqu'à réactivation manuelle (null).
     */
    public function pauseAi(?CarbonInterface $until = null): void
    {
        $this->forceFill(['ai_paused_until' => $until ?? CarbonImmutable::parse(self::INDEFINITE_PAUSE)])->save();
    }

    public function resumeAi(): void
    {
        $this->forceFill(['ai_paused_until' => null])->save();
    }

    /**
     * Le gérant vient d'écrire au client : pause de la durée choisie par le business
     * (Business::ai_pause_hours, 6 h par défaut, 0 : jusqu'à réactivation), prolongée à
     * chaque message. Une pause plus longue en cours (manuelle, sans limite) est gardée.
     */
    public function pauseAfterManagerMessage(Business $business): void
    {
        $hours = $business->ai_pause_hours ?? Business::DEFAULT_AI_PAUSE_HOURS;
        $until = $hours === Business::MANUAL_AI_PAUSE ? CarbonImmutable::parse(self::INDEFINITE_PAUSE) : now()->addHours($hours);

        if ($this->ai_paused_until !== null && $this->ai_paused_until->gte($until)) {
            return;
        }

        $this->pauseAi($until);
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
