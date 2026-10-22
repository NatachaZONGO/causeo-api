<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse écrite par le gérant (dashboard ou app WhatsApp Business), gardée pour
 * apprendre son style. Chaque business n'utilise que ses propres réponses.
 */
class ManagerReply extends Model
{
    use HasUuids;

    public const SOURCE_DASHBOARD = 'dashboard';

    public const SOURCE_ECHO = 'echo';

    /** Reprise des réponses enregistrées avant l'apprentissage du style. */
    public const SOURCE_HISTORY = 'history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'message_id',
        'customer_question',
        'reply',
        'source',
    ];

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
