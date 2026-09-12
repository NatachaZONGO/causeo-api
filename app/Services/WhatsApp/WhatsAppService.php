<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private Client $client;

    private string $phoneNumberId;

    public function __construct(?string $token = null, ?string $phoneNumberId = null)
    {
        $this->client = new Client([
            'base_uri' => rtrim(config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.($token ?? config('services.whatsapp.token')),
                'Content-Type' => 'application/json',
            ],
            'verify' => config('services.curl_ca_bundle', true),
        ]);

        $this->phoneNumberId = $phoneNumberId ?? (string) config('services.whatsapp.phone_number_id');
    }

    /**
     * Construire une instance pour un business : utilise son token/numéro
     * propres si connecté via Embedded Signup, sinon retombe sur le .env.
     */
    public static function forBusiness(Business $business): self
    {
        return new self(
            $business->whatsapp_token ?: null,
            $business->whatsapp_phone_number_id ?: null,
        );
    }

    /**
     * Envoyer un message texte à un numéro WhatsApp.
     *
     * @return array<string, mixed>
     */
    public function sendMessage(string $to, string $text): array
    {
        try {
            $response = $this->client->post("{$this->phoneNumberId}/messages", [
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ],
            ]);

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            Log::error('WhatsAppService::sendMessage a échoué', [
                'to' => $to,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Envoyer une image via un lien URL public.
     *
     * @return array<string, mixed>
     */
    public function sendImage(string $to, string $imageUrl, ?string $caption = null): array
    {
        $image = ['link' => $imageUrl];
        if ($caption !== null && $caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->postMessage($to, [
            'type' => 'image',
            'image' => $image,
        ], ['to' => $to, 'image_url' => $imageUrl]);
    }

    /**
     * Envoyer un document (PDF, etc.) via un lien URL public.
     *
     * @return array<string, mixed>
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, ?string $caption = null): array
    {
        $document = [
            'link' => $documentUrl,
            'filename' => $filename,
        ];
        if ($caption !== null && $caption !== '') {
            $document['caption'] = $caption;
        }

        return $this->postMessage($to, [
            'type' => 'document',
            'document' => $document,
        ], ['to' => $to, 'document_url' => $documentUrl]);
    }

    /**
     * Envoyer un média générique en choisissant la bonne méthode selon le type.
     *
     * @param  array{type: string, url: string, caption?: ?string, filename?: ?string}  $media
     * @return array<string, mixed>
     */
    public function sendMediaMessage(string $to, array $media): array
    {
        $url = $media['url'] ?? '';
        $caption = $media['caption'] ?? null;

        if ($url === '') {
            return [];
        }

        return match ($media['type'] ?? null) {
            'document' => $this->sendDocument($to, $url, $media['filename'] ?? 'document', $caption),
            'image', 'catalog' => $this->sendImage($to, $url, $caption),
            default => [],
        };
    }

    /**
     * Envoyer une charge utile "messages" à l'API WhatsApp Cloud.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $logContext
     * @return array<string, mixed>
     */
    private function postMessage(string $to, array $payload, array $logContext): array
    {
        try {
            $response = $this->client->post("{$this->phoneNumberId}/messages", [
                'json' => array_merge([
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                ], $payload),
            ]);

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            Log::error('WhatsAppService::postMessage a échoué', array_merge($logContext, [
                'message' => $e->getMessage(),
            ]));

            return [];
        }
    }

    /**
     * Parser un webhook WhatsApp entrant.
     *
     * @param  array<string, mixed>  $payload
     * @return array{from: string, message_id: string, text: string, customer_name: ?string}|null
     */
    public function parseIncomingMessage(array $payload): ?array
    {
        $value = $payload['entry'][0]['changes'][0]['value'] ?? [];

        $message = $value['messages'][0] ?? null;

        if ($message === null || ($message['type'] ?? null) !== 'text') {
            return null;
        }

        $contactName = $value['contacts'][0]['profile']['name'] ?? null;

        return [
            'from' => $message['from'] ?? '',
            'message_id' => $message['id'] ?? '',
            'text' => $message['text']['body'] ?? '',
            'customer_name' => $contactName,
        ];
    }

    /**
     * Marquer un message entrant comme lu.
     */
    public function markAsRead(string $messageId): void
    {
        try {
            $this->client->post("{$this->phoneNumberId}/messages", [
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $messageId,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('WhatsAppService::markAsRead a échoué', [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
