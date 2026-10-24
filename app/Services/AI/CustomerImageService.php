<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Models\Message;
use App\Services\WhatsApp\MediaMessage;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Photo envoyée par un client, préparée pour Claude : téléchargée chez Meta, réduite
 * à MAX_DIMENSION pixels de côté (moins de tokens, donc moins cher), encodée en base64.
 * Rien n'est gardé sur le disque.
 */
class CustomerImageService
{
    /** Plus grand côté de l'image envoyée à Claude. */
    public const MAX_DIMENSION = 1024;

    /** Les photos WhatsApp font au plus 5 Mo. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Taille maximale d'une image non réduite (Claude refuse plus de 5 Mo en base64). */
    private const CLAUDE_MAX_BYTES = 3_700_000;

    public function __construct(
        private readonly WhatsAppMediaService $media,
    ) {}

    /**
     * Bloc image pour Claude, ou null si la photo ne peut pas être montrée (format non
     * pris en charge, fichier expiré ou illisible).
     *
     * @return array{type: 'image', source: array{type: 'base64', media_type: string, data: string}}|null
     */
    public function prepare(Business $business, Message $message): ?array
    {
        $mimeType = strtolower(trim(explode(';', (string) ($message->metadata['mime_type'] ?? ''))[0]));

        if (! in_array($mimeType, MediaMessage::ANALYZABLE_IMAGE_TYPES, true) || empty($message->metadata['media_id'])) {
            Log::info('CustomerImageService: format de photo non pris en charge.', ['message_id' => $message->id, 'mime_type' => $mimeType]);

            return null;
        }

        try {
            $file = $this->media->download($business, (string) $message->metadata['media_id'], self::MAX_BYTES);
        } catch (Throwable $e) {
            Log::warning('CustomerImageService: téléchargement de la photo impossible.', ['message_id' => $message->id, 'exception' => $e::class]);

            return null;
        }

        [$body, $mimeType] = $this->resize($file['body'], $mimeType);

        if ($body === null) {
            Log::warning('CustomerImageService: photo illisible ou trop lourde.', ['message_id' => $message->id, 'size' => $file['size']]);

            return null;
        }

        return [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => base64_encode($body)],
        ];
    }

    /**
     * Réduire l'image à MAX_DIMENSION pixels (JPEG). Sans l'extension GD, ou si GD ne
     * lit pas le format, l'image est envoyée telle quelle si elle n'est pas trop lourde.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resize(string $body, string $mimeType): array
    {
        $size = @getimagesizefromstring($body);

        if ($size === false) {
            return [null, $mimeType];
        }

        [$width, $height] = $size;

        if (max($width, $height) <= self::MAX_DIMENSION) {
            return [strlen($body) <= self::CLAUDE_MAX_BYTES ? $body : null, $mimeType];
        }

        $source = function_exists('imagecreatefromstring') ? @imagecreatefromstring($body) : false;

        if ($source === false) {
            return [strlen($body) <= self::CLAUDE_MAX_BYTES ? $body : null, $mimeType];
        }

        $ratio = self::MAX_DIMENSION / max($width, $height);
        $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));

        // Fond blanc sous les zones transparentes (PNG, WebP), le JPEG n'en a pas.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        ob_start();
        imagejpeg($target, null, 85);
        $resized = (string) ob_get_clean();

        return [$resized !== '' ? $resized : null, 'image/jpeg'];
    }
}
