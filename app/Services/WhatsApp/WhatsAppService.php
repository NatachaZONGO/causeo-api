<?php

namespace App\Services\WhatsApp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private Client $client;

    private string $phoneNumberId;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim(config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.config('services.whatsapp.token'),
                'Content-Type' => 'application/json',
            ],
            'verify' => config('services.curl_ca_bundle', true),
        ]);

        $this->phoneNumberId = (string) config('services.whatsapp.phone_number_id');
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
