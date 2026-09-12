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
     * Connecter WhatsApp à partir du WABA ID et du phone_number_id
     * renvoyés par le message event d'Embedded Signup.
     */
    public function exchangeToken(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
        ]);

        $systemToken = config('services.whatsapp.token');

        if (empty($systemToken)) {
            return response()->json(['message' => 'Configuration serveur manquante.'], 500);
        }

        try {
            $client = new Client([
                'base_uri' => 'https://graph.facebook.com/v21.0/',
                'headers' => ['Authorization' => 'Bearer '.$systemToken],
                'verify' => config('services.curl_ca_bundle', true),
            ]);

            // Vérifier que le phone_number_id est valide en récupérant ses infos
            $phoneResponse = $client->get("{$data['phone_number_id']}", [
                'query' => ['fields' => 'id,verified_name,display_phone_number,quality_rating'],
            ]);
            $phoneInfo = json_decode((string) $phoneResponse->getBody(), true);

            // S'abonner aux webhooks pour ce WABA
            try {
                $client->post("{$data['waba_id']}/subscribed_apps");
            } catch (\Throwable $e) {
                Log::warning('Webhook subscription failed', ['error' => $e->getMessage()]);
            }

            // Mettre à jour le business
            $business->update([
                'whatsapp_phone_number_id' => $data['phone_number_id'],
                'whatsapp_waba_id' => $data['waba_id'],
                'whatsapp_token' => $systemToken,
                'whatsapp_display_name' => $phoneInfo['verified_name'] ?? $phoneInfo['display_phone_number'] ?? null,
                'whatsapp_verified' => true,
                'whatsapp_connected_at' => now(),
            ]);

            return response()->json([
                'message' => 'WhatsApp connecté avec succès !',
                'connected' => true,
                'display_name' => $business->whatsapp_display_name,
                'phone_number' => $phoneInfo['display_phone_number'] ?? null,
                'connected_at' => $business->whatsapp_connected_at,
            ]);

        } catch (\Throwable $e) {
            Log::error('WhatsApp connect failed', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur: '.$e->getMessage(),
            ], 422);
        }
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
