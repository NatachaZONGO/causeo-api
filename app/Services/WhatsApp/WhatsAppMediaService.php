<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Médias reçus sur WhatsApp (messages vocaux) : GET /{media_id} donne une URL
 * temporaire, à télécharger avec le même token. Rien n'est gardé : le fichier est
 * relu chez Meta à chaque besoin.
 */
class WhatsAppMediaService
{
    /**
     * Télécharger un média, au plus $maxBytes octets.
     *
     * @return array{body: string, mime_type: string, size: int}
     *
     * @throws MediaTooLargeException si le média dépasse $maxBytes
     * @throws RuntimeException si Meta refuse ou ne répond pas
     */
    public function download(Business $business, string $mediaId, ?int $maxBytes = null): array
    {
        try {
            $info = $this->http($business)->get($this->url($mediaId));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Connexion à Meta impossible.', 0, $e);
        }

        if (! $info->successful() || ! is_string($info->json('url'))) {
            throw new RuntimeException("Média WhatsApp introuvable (HTTP {$info->status()}).");
        }

        $declared = (int) $info->json('file_size', 0);
        if ($maxBytes !== null && $declared > $maxBytes) {
            throw new MediaTooLargeException($declared);
        }

        try {
            $file = $this->http($business)->timeout(60)->get($info->json('url'));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Téléchargement du média impossible.', 0, $e);
        }

        if (! $file->successful()) {
            throw new RuntimeException("Téléchargement du média refusé (HTTP {$file->status()}).");
        }

        $body = $file->body();
        if ($maxBytes !== null && strlen($body) > $maxBytes) {
            throw new MediaTooLargeException(strlen($body));
        }

        return [
            'body' => $body,
            'mime_type' => (string) ($info->json('mime_type') ?: $file->header('Content-Type') ?: 'application/octet-stream'),
            'size' => strlen($body),
        ];
    }

    /**
     * Token du business s'il en a un, sinon celui de l'utilisateur système (WHATSAPP_TOKEN).
     */
    private function http(Business $business): PendingRequest
    {
        return Http::withToken((string) ($business->whatsapp_token ?: config('services.whatsapp.token')))
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->timeout(20);
    }

    private function url(string $mediaId): string
    {
        return rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/'.rawurlencode($mediaId);
    }
}
