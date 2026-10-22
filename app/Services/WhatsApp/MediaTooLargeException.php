<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Média WhatsApp plus lourd que la limite acceptée.
 */
class MediaTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $bytes)
    {
        parent::__construct("Média trop lourd ({$bytes} octets).");
    }
}
