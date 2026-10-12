<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Appels à la Cloud API pour le numéro Causeo qui alerte les gérants
 * (services.causeo_notify), avec le token de l'utilisateur système.
 */
class CauseoNotifyClient
{
    public function isConfigured(): bool
    {
        return $this->phoneNumberId() !== '' && (string) config('services.whatsapp.token') !== '';
    }

    public function phoneNumberId(): string
    {
        return trim((string) config('services.causeo_notify.phone_number_id'));
    }

    /**
     * Envoyer un modèle approuvé, avec les paramètres du corps dans l'ordre ({{1}}, {{2}}…).
     *
     * @param  list<string>  $parameters
     */
    public function sendTemplate(string $to, string $template, array $parameters): CauseoNotifyResult
    {
        return $this->post("{$this->phoneNumberId()}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D/', '', $to),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => (string) config('services.causeo_notify.language', 'fr')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $text) => ['type' => 'text', 'text' => $text], $parameters),
                ]],
            ],
        ]);
    }

    /**
     * Enregistrer le numéro sur la Cloud API (POST /{phone_number_id}/register).
     * Le PIN est celui de la vérification en deux étapes du numéro.
     */
    public function register(string $pin): CauseoNotifyResult
    {
        return $this->post("{$this->phoneNumberId()}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): CauseoNotifyResult
    {
        try {
            $response = $this->http()->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            return new CauseoNotifyResult(false, 0, [], ['message' => 'Connexion à Meta impossible : '.$e->getMessage()]);
        }

        return $this->result($response);
    }

    private function result(Response $response): CauseoNotifyResult
    {
        $body = (array) ($response->json() ?? []);

        if ($response->successful()) {
            return new CauseoNotifyResult(true, $response->status(), $body);
        }

        return new CauseoNotifyResult(false, $response->status(), $body, (array) ($body['error'] ?? ['message' => trim($response->body())]));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.whatsapp.token'))
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->acceptJson()
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/'.$path;
    }
}
