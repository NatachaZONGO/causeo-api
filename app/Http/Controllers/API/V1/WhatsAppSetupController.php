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

            // Lister les WABAs accessibles via le System User Token
            $sharedWabasResponse = $client->get('me/whatsapp_business_accounts', [
                'query' => ['fields' => 'id,name,account_review_status'],
            ]);
            $sharedWabas = json_decode((string) $sharedWabasResponse->getBody(), true);

            Log::info('Shared WABAs', ['wabas' => $sharedWabas]);

            $wabaData = $sharedWabas['data'] ?? [];

            if (empty($wabaData)) {
                return response()->json([
                    'message' => 'Aucun compte WhatsApp Business trouvé. Complétez toutes les étapes du signup Facebook.',
                ], 422);
            }

            // Trouver un WABA qui n'est pas encore utilisé par un autre business
            $usedWabaIds = Business::whereNotNull('whatsapp_waba_id')
                ->where('id', '!=', $business->id)
                ->pluck('whatsapp_waba_id')
                ->toArray();

            $availableWaba = null;
            foreach (array_reverse($wabaData) as $waba) {
                if (! in_array($waba['id'], $usedWabaIds)) {
                    $availableWaba = $waba;
                    break;
                }
            }

            if (! $availableWaba) {
                $availableWaba = end($wabaData);
            }

            $wabaId = $availableWaba['id'];

            // Récupérer les numéros de ce WABA
            $phonesResponse = $client->get("{$wabaId}/phone_numbers", [
                'query' => ['fields' => 'id,verified_name,display_phone_number,quality_rating'],
            ]);
            $phones = json_decode((string) $phonesResponse->getBody(), true);

            $phoneData = $phones['data'] ?? [];

            if (empty($phoneData)) {
                return response()->json([
                    'message' => 'Aucun numéro WhatsApp trouvé pour ce compte. Ajoutez un numéro dans Facebook.',
                ], 422);
            }

            // Trouver un numéro pas encore utilisé
            $usedPhoneIds = Business::whereNotNull('whatsapp_phone_number_id')
                ->where('id', '!=', $business->id)
                ->pluck('whatsapp_phone_number_id')
                ->toArray();

            $availablePhone = null;
            foreach (array_reverse($phoneData) as $phone) {
                if (! in_array($phone['id'], $usedPhoneIds)) {
                    $availablePhone = $phone;
                    break;
                }
            }

            if (! $availablePhone) {
                return response()->json([
                    'message' => 'Tous les numéros WhatsApp sont déjà utilisés par d\'autres entreprises.',
                ], 422);
            }

            // S'abonner aux webhooks
            try {
                $client->post("{$wabaId}/subscribed_apps");
            } catch (\Throwable $e) {
                Log::warning('Webhook subscription failed', ['error' => $e->getMessage()]);
            }

            // Mettre à jour le business
            $business->update([
                'whatsapp_phone_number_id' => $availablePhone['id'],
                'whatsapp_waba_id' => $wabaId,
                'whatsapp_token' => $systemToken,
                'whatsapp_display_name' => $availablePhone['verified_name'] ?? $availablePhone['display_phone_number'] ?? null,
                'whatsapp_verified' => true,
                'whatsapp_connected_at' => now(),
            ]);

            return response()->json([
                'message' => 'WhatsApp a été connecté avec succès !',
                'connected' => true,
                'display_name' => $business->whatsapp_display_name,
                'phone_number' => $availablePhone['display_phone_number'] ?? null,
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
