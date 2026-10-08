<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Envoi impossible : l'entreprise n'a pas de numéro WhatsApp connecté. On ne
 * retombe jamais sur le numéro global du .env.
 */
class WhatsAppNotConnectedException extends RuntimeException
{
    public function __construct(string $message = "WhatsApp n'est pas connecté pour cette entreprise.")
    {
        parent::__construct($message);
    }
}
