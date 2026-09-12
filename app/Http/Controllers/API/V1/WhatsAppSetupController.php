<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppSetupController extends Controller
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v21.0/',
            'verify' => config('services.curl_ca_bundle', true),
        ]);
    }

    /**
     * Échanger le code retourné par Embedded Signup contre un token
     * et récupérer les identifiants WhatsApp Business (WABA + numéro).
     */
    public function exchangeToken(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'code' => ['required', 'string'],
        ], [
            'code.required' => 'Le code d\'échange est obligatoire.',
        ]);

        try {
            $token = $this->getAccessToken($data['code']);
            $wabaId = $this->getWabaId($token);
            $phoneNumber = $this->getPhoneNumber($wabaId, $token);

            $this->subscribeAppToWebhooks($wabaId, $token);

            $business->update([
                'whatsapp_phone_number_id' => $phoneNumber['id'],
                'whatsapp_waba_id' => $wabaId,
                'whatsapp_token' => $token,
                'whatsapp_display_name' => $phoneNumber['verified_name'] ?? null,
                'whatsapp_verified' => true,
                'whatsapp_connected_at' => now(),
            ]);
        } catch (RuntimeException $e) {
            Log::error('WhatsApp connect failed', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'code' => $e->getCode(),
            ]);

            $message = config('app.debug')
                ? 'La connexion à WhatsApp a échoué: '.$e->getMessage()
                : 'La connexion à WhatsApp a échoué. Veuillez réessayer.';

            return response()->json([
                'message' => $message,
            ], 422);
        }

        return response()->json([
            'message' => 'WhatsApp a été connecté avec succès.',
            'business' => $business,
        ]);
    }

    /**
     * Statut de la connexion WhatsApp d'une entreprise.
     */
    public function status(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $connected = ! empty($business->whatsapp_phone_number_id) && $business->whatsapp_verified === true;

        return response()->json([
            'connected' => $connected,
            'display_name' => $business->whatsapp_display_name,
            'phone_number_id' => $this->maskPhoneNumberId($business->whatsapp_phone_number_id),
            'connected_at' => $business->whatsapp_connected_at,
        ]);
    }

    /**
     * Déconnecter WhatsApp d'une entreprise.
     */
    public function disconnect(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $business->update([
            'whatsapp_phone_number_id' => null,
            'whatsapp_waba_id' => null,
            'whatsapp_token' => null,
            'whatsapp_verified' => false,
            'whatsapp_connected_at' => null,
            'whatsapp_display_name' => null,
        ]);

        return response()->json([
            'message' => 'WhatsApp a été déconnecté avec succès.',
        ]);
    }

    /**
     * Échanger le code Embedded Signup contre un token d'accès longue durée.
     */
    private function getAccessToken(string $code): string
    {
        try {
            $response = $this->client->get('oauth/access_token', [
                'query' => [
                    'client_id' => config('services.facebook.app_id'),
                    'client_secret' => config('services.facebook.app_secret'),
                    'code' => $code,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            if (empty($payload['access_token'])) {
                throw new RuntimeException('Aucun token retourné par Meta.');
            }

            return $payload['access_token'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('Échec de l\'échange du code contre un token.', 0, $e);
        }
    }

    /**
     * Récupérer l'identifiant du WhatsApp Business Account (WABA) partagé
     * lors de l'Embedded Signup, via l'introspection du token.
     */
    private function getWabaId(string $token): string
    {
        try {
            $response = $this->client->get('debug_token', [
                'query' => [
                    'input_token' => $token,
                    'access_token' => config('services.facebook.app_id').'|'.config('services.facebook.app_secret'),
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            $scopes = $payload['data']['granular_scopes'] ?? [];

            foreach ($scopes as $scope) {
                if (($scope['scope'] ?? null) === 'whatsapp_business_management' && ! empty($scope['target_ids'])) {
                    return $scope['target_ids'][0];
                }
            }

            throw new RuntimeException('Aucun WABA trouvé pour ce token.');
        } catch (GuzzleException $e) {
            throw new RuntimeException('Échec de la récupération du WABA.', 0, $e);
        }
    }

    /**
     * Récupérer le numéro de téléphone WhatsApp associé au WABA.
     *
     * @return array{id: string, verified_name: ?string}
     */
    private function getPhoneNumber(string $wabaId, string $token): array
    {
        try {
            $response = $this->client->get("{$wabaId}/phone_numbers", [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            $phoneNumber = $payload['data'][0] ?? null;

            if ($phoneNumber === null || empty($phoneNumber['id'])) {
                throw new RuntimeException('Aucun numéro WhatsApp trouvé pour ce WABA.');
            }

            return [
                'id' => $phoneNumber['id'],
                'verified_name' => $phoneNumber['verified_name'] ?? null,
            ];
        } catch (GuzzleException $e) {
            throw new RuntimeException('Échec de la récupération du numéro WhatsApp.', 0, $e);
        }
    }

    /**
     * Abonner l'application aux webhooks du WABA.
     */
    private function subscribeAppToWebhooks(string $wabaId, string $token): void
    {
        try {
            $this->client->post("{$wabaId}/subscribed_apps", [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);
        } catch (GuzzleException $e) {
            Log::error('WhatsAppSetupController::subscribeAppToWebhooks a échoué', [
                'waba_id' => $wabaId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Masquer partiellement un identifiant de numéro de téléphone.
     */
    private function maskPhoneNumberId(?string $phoneNumberId): ?string
    {
        if ($phoneNumberId === null || $phoneNumberId === '') {
            return null;
        }

        $visible = 4;
        $length = strlen($phoneNumberId);

        if ($length <= $visible) {
            return $phoneNumberId;
        }

        return str_repeat('*', $length - $visible).substr($phoneNumberId, -$visible);
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
