<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\WhatsApp\WhatsAppAccountService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSetupController extends Controller
{
    /**
     * Connecter WhatsApp à partir du WABA ID, du phone_number_id et, si fourni,
     * du code renvoyés par Embedded Signup. Le code est échangé contre un token
     * business propre au client ; à défaut, on garde le token système.
     * Le numéro n'est jamais enregistré (/register) : en Coexistence, il l'est déjà.
     */
    public function exchangeToken(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $token = ! empty($data['code']) ? $this->exchangeCode($business, $data['code']) : null;

        if ($token === null) {
            if (empty($data['code'])) {
                Log::warning('WhatsAppSetupController: connexion sans code Embedded Signup, repli sur le token système.', [
                    'business_id' => $business->id,
                ]);
            }

            $token = (string) config('services.whatsapp.token');
        }

        if ($token === '') {
            return response()->json(['message' => 'Configuration serveur manquante.'], 500);
        }

        try {
            // Vérifier que le phone_number_id est valide en récupérant ses infos.
            $phoneInfo = $this->graph($token)
                ->get($this->graphUrl($data['phone_number_id']), ['fields' => 'id,verified_name,display_phone_number,quality_rating'])
                ->throw()
                ->json();

            // S'abonner aux webhooks pour ce WABA.
            $subscription = $this->graph($token)->post($this->graphUrl("{$data['waba_id']}/subscribed_apps"));
            if (! $subscription->successful()) {
                Log::warning('WhatsAppSetupController: abonnement aux webhooks du WABA refusé.', [
                    'business_id' => $business->id,
                    'status' => $subscription->status(),
                    'error' => $subscription->json('error.message'),
                ]);
            }

            $business->update([
                'whatsapp_phone_number_id' => $data['phone_number_id'],
                'whatsapp_waba_id' => $data['waba_id'],
                'whatsapp_token' => $token,
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
     * Échanger le code Embedded Signup contre un token business
     * (GET /oauth/access_token avec client_id, client_secret et code).
     * Renvoie null en cas d'échec ; le code, le secret et le token ne sont jamais journalisés.
     */
    private function exchangeCode(Business $business, string $code): ?string
    {
        try {
            $response = Http::withOptions(['verify' => config('services.curl_ca_bundle', true)])
                ->timeout(15)
                ->get($this->graphUrl('oauth/access_token'), [
                    'client_id' => config('services.facebook.app_id'),
                    'client_secret' => config('services.facebook.app_secret'),
                    'code' => $code,
                ]);

            $token = $response->json('access_token');

            if ($response->successful() && is_string($token) && $token !== '') {
                return $token;
            }

            Log::warning('WhatsAppSetupController: échange du code Embedded Signup refusé, repli sur le token système.', [
                'business_id' => $business->id,
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_code' => $response->json('error.code'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsAppSetupController: échange du code Embedded Signup impossible, repli sur le token système.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);
        }

        return null;
    }

    private function graph(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->timeout(15);
    }

    private function graphUrl(string $path): string
    {
        return rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/'.$path;
    }

    /**
     * Demander l'activation manuelle de WhatsApp (sans Embedded Signup) :
     * l'utilisateur fournit son numéro et le nom affiché, l'équipe Causeo
     * configure ensuite le WABA et le phone_number_id côté admin.
     */
    public function requestActivation(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'display_name' => ['required', 'string'],
        ]);

        $business->update([
            'phone' => $data['phone'],
            'whatsapp_display_name' => $data['display_name'],
            'whatsapp_phone_number_id' => null,
            'whatsapp_waba_id' => null,
            'whatsapp_token' => null,
            'whatsapp_verified' => false,
            'whatsapp_connected_at' => null,
        ]);

        // TODO: Notifier l'admin (email, notification dashboard)
        Log::info('WhatsApp activation requested', [
            'business_id' => $business->id,
            'phone' => $data['phone'],
            'display_name' => $data['display_name'],
        ]);

        return response()->json([
            'message' => 'Demande d\'activation envoyée ! Notre équipe vous contactera sous 24h.',
            'connected' => false,
            'pending' => true,
            'display_name' => $data['display_name'],
            'phone' => $data['phone'],
            'requested_at' => $business->updated_at,
        ]);
    }

    /**
     * Statut de la connexion WhatsApp d'une entreprise.
     */
    public function status(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $connected = ! empty($business->whatsapp_phone_number_id) && $business->whatsapp_verified === true;
        $pending = ! $connected && ! empty($business->whatsapp_display_name);

        return response()->json([
            'connected' => $connected,
            'pending' => $pending,
            'display_name' => $business->whatsapp_display_name,
            'phone' => $business->phone,
            'connected_at' => $business->whatsapp_connected_at,
            'requested_at' => $pending ? $business->updated_at : null,
            'messages_this_month' => (int) $business->monthly_message_count,
        ]);
    }

    /**
     * Déconnecter WhatsApp d'une entreprise : désabonner l'app du WABA chez Meta
     * (sans jamais désenregistrer le numéro), puis vider les colonnes, même si
     * l'appel à Meta échoue.
     */
    public function disconnect(Business $business, WhatsAppAccountService $accounts): JsonResponse
    {
        $this->checkOwnership($business);

        $unsubscribed = $accounts->unsubscribe($business);

        $accounts->markDisconnected($business);

        return response()->json([
            'message' => 'WhatsApp a été déconnecté avec succès.',
            'meta_unsubscribed' => $unsubscribed,
        ]);
    }


    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
