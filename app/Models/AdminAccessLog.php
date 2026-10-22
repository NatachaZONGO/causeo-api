<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Accès d'un admin aux données d'un business, journalisé sans leur contenu.
 */
class AdminAccessLog extends Model
{
    public const VOICE_MESSAGE_LISTENED = 'voice_message_listened';

    /** Ouverture d'une conversation (journalisée au plus une fois par période, voir ConversationController). */
    public const CONVERSATION_VIEWED = 'conversation_viewed';

    /** Une entrée de journal ne change jamais : pas de updated_at. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admin_id',
        'action',
        'message_id',
        'business_id',
        'conversation_id',
    ];
}
