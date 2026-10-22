<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Escalation extends Model
{
    use HasFactory, HasUuids;

    public const PENDING = 'pending';

    /** Le gérant a répondu au client (dashboard ou téléphone). */
    public const ANSWERED = 'answered';

    /** Le gérant l'a marquée comme traitée, sans répondre. */
    public const RESOLVED = 'resolved';

    /** Restée en attente plus de EXPIRES_AFTER_HOURS heures, ou remplacée par une plus récente. */
    public const EXPIRED = 'expired';

    public const STATUSES = [self::PENDING, self::ANSWERED, 'ignored', self::RESOLVED, self::EXPIRED];

    public const EXPIRES_AFTER_HOURS = 24;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'message_id',
        'business_id',
        'customer_question',
        'human_response',
        'status',
        'answered_at',
        'reason',
    ];

    /** Escalade d'un message vocal non transcrit : la question n'est pas le texte du client. */
    public function isUntranscribedVoice(): bool
    {
        return is_string($this->reason) && str_starts_with($this->reason, 'voice_');
    }

    /**
     * Notifier le gérant à chaque nouvelle escalade.
     */
    protected static function booted(): void
    {
        static::created(function (Escalation $escalation): void {
            app(NotificationService::class)->notifyEscalation($escalation);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
