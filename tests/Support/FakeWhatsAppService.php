<?php

namespace Tests\Support;

use App\Services\WhatsApp\WhatsAppService;

/**
 * Envoi WhatsApp factice : garde les textes envoyés au client, sans appel à Meta.
 */
class FakeWhatsAppService extends WhatsAppService
{
    /** @var list<string> */
    public static array $sent = [];

    public function __construct()
    {
    }

    public function sendMessage(string $to, string $text): array
    {
        self::$sent[] = $text;

        return ['messages' => [['id' => 'wamid.OUT'.count(self::$sent)]]];
    }

    public function markAsRead(string $messageId): void
    {
    }
}
