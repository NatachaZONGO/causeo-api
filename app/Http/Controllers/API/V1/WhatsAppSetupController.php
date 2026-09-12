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

        // Le code n'est plus échangé - on utilise le System User Token
        // pour récupérer les WABAs partagés avec notre app
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

            // Récupérer tous les WABAs partagés avec notre app via le Business Portfolio
            $appId = config('services.facebook.app_id');

            // Lister les WABAs accessibles
            $response = $client->get('app/subscribed_apps_to_wabas');
            $payload = json_decode((string) $response->getBody(), true);

            Log::info('WABAs response', ['payload' => $payload]);

            // Si ça ne marche pas, essayer via le debug_token du code reçu
            // pour au moins identifier le WABA partagé
            $data = $request->validate([
                'code' => ['nullable', 'string'],
                'token' => ['nullable', 'string'],
            ]);

            // Approche alternative : lister les WABAs du Business Portfolio de Devora
            $businessPortfolioId = config('services.facebook.business_id', '');

            if (! empty($businessPortfolioId)) {
                $wabasResponse = $client->get("{$businessPortfolioId}/owned_whatsapp_business_accounts", [
                    'query' => ['fields' => 'id,name,account_review_status'],
                ]);
                $wabas = json_decode((string) $wabasResponse->getBody(), true);
                Log::info('Owned WABAs', ['wabas' => $wabas]);
            }

            // Lister tous les phone numbers accessibles
            // On cherche le dernier WABA ajouté (celui que le client vient de partager)
            $sharedWabasResponse = $client->get('me/whatsapp_business_accounts', [
                'query' => ['fields' => 'id,name,account_review_status'],
            ]);
            $sharedWabas = json_decode((string) $sharedWabasResponse->getBody(), true);

            Log::info('Shared WABAs via me/', ['wabas' => $sharedWabas]);

            $wabaData = $sharedWabas['data'] ?? [];

            if (empty($wabaData)) {
                return response()->json([
                    'message' => 'Aucun compte WhatsApp Business trouvé. Assurez-vous d\'avoir complété toutes les étapes.',
                    'debug' => $sharedWabas,
                ], 422);
            }

            // Prendre le dernier WABA (le plus récemment partagé)
            $waba = end($wabaData);
            $wabaId = $waba['id'];

            // Récupérer les numéros de téléphone de ce WABA
            $phonesResponse = $client->get("{$wabaId}/phone_numbers", [
                'query' => ['fields' => 'id,verified_name,display_phone_number,quality_rating'],
            ]);
            $phones = json_decode((string) $phonesResponse->getBody(), true);

            Log::info('Phone numbers for WABA', ['waba_id' => $wabaId, 'phones' => $phones]);

            $phoneData = $phones['data'] ?? [];

            if (empty($phoneData)) {
                return response()->json([
                    'message' => 'Aucun numéro WhatsApp trouvé. Veuillez ajouter un numéro dans la configuration.',
                ], 422);
            }

            $phone = end($phoneData);

            // S'abonner aux webhooks pour ce WABA
            try {
                $client->post("{$wabaId}/subscribed_apps");
            } catch (\Throwable $e) {
                Log::warning('Webhook subscription failed', ['error' => $e->getMessage()]);
            }

            // Mettre à jour le business
            $business->update([
                'whatsapp_phone_number_id' => $phone['id'],
                'whatsapp_waba_id' => $wabaId,
                'whatsapp_token' => $systemToken,
                'whatsapp_display_name' => $phone['verified_name'] ?? $phone['display_phone_number'] ?? null,
                'whatsapp_verified' => true,
                'whatsapp_connected_at' => now(),
            ]);

            return response()->json([
                'message' => 'WhatsApp a été connecté avec succès.',
                'connected' => true,
                'display_name' => $business->whatsapp_display_name,
                'phone_number_id' => $phone['id'],
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
