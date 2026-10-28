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

    /** @var list<array{message_id: string, typing: bool}> */
    public static array $read = [];

    public function markAsRead(string $messageId, bool $typingIndicator = false): void
    {
        self::$read[] = ['message_id' => $messageId, 'typing' => $typingIndicator];
    }

    /** @var list<string> adresses des images envoyées */
    public static array $images = [];

    public function sendImage(string $to, string $imageUrl, ?string $caption = null): array
    {
        self::$images[] = $imageUrl;

        return ['messages' => [['id' => 'wamid.IMG-OUT'.count(self::$images)]]];
    }
}
