<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppAccountService
{
    /**
     * Colonnes vidées quand un business est déconnecté de WhatsApp.
     *
     * @var array<string, mixed>
     */
    private const DISCONNECTED = [
        'whatsapp_phone_number_id' => null,
        'whatsapp_waba_id' => null,
        'whatsapp_token' => null,
        'whatsapp_verified' => false,
        'whatsapp_connected_at' => null,
        'whatsapp_display_name' => null,
    ];

    /**
     * Désabonner l'app du WABA du client (DELETE /{waba_id}/subscribed_apps).
     * On ne désenregistre jamais le numéro (/deregister) : en Coexistence, cela
     * casserait l'app WhatsApp Business du client.
     *
     * Renvoie true si Meta a confirmé le désabonnement. Une erreur est journalisée
     * sans être relancée : la déconnexion en base doit avoir lieu quoi qu'il arrive.
     */
    public function unsubscribe(Business $business): bool
    {
        if (empty($business->whatsapp_waba_id)) {
            return false;
        }

        try {
            $response = Http::withToken($business->whatsapp_token ?: (string) config('services.whatsapp.token'))
                ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
                ->timeout(15)
                ->delete($this->graphUrl("{$business->whatsapp_waba_id}/subscribed_apps"));

            if ($response->successful() && $response->json('success') === true) {
                return true;
            }

            Log::error('WhatsAppAccountService: désabonnement du WABA refusé par Meta', [
                'business_id' => $business->id,
                'waba_id' => $business->whatsapp_waba_id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsAppAccountService: désabonnement du WABA impossible', [
                'business_id' => $business->id,
                'waba_id' => $business->whatsapp_waba_id,
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Marquer le business comme déconnecté (sans appel à Meta).
     */
    public function markDisconnected(Business $business): void
    {
        $business->update(self::DISCONNECTED);
    }

    /**
     * Déconnexion venue de Meta ou du téléphone du client : on vide les colonnes
     * et on prévient le gérant.
     */
    public function disconnectFromMeta(Business $business, string $event, ?string $detail = null): void
    {
        $displayName = $business->whatsapp_display_name;

        $this->markDisconnected($business);

        try {
            Notification::create([
                'business_id' => $business->id,
                'type' => 'whatsapp',
                'title' => 'WhatsApp déconnecté',
                'body' => ($displayName ? "Le numéro {$displayName} n'est plus relié à Causeo" : "Votre numéro WhatsApp n'est plus relié à Causeo")
                    ." ({$this->describe($event)}"
                    .($detail ? ", {$detail}" : '')
                    .'). L\'assistant ne répond plus aux clients : reconnectez WhatsApp depuis la page WhatsApp.',
                'data' => ['event' => $event],
            ]);
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas empêcher la déconnexion.
            Log::error('WhatsAppAccountService: notification de déconnexion impossible', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function describe(string $event): string
    {
        return match ($event) {
            'PARTNER_REMOVED' => 'accès de Causeo retiré dans Meta',
            'PARTNER_APP_UNINSTALLED' => 'autorisations de l\'application retirées',
            'ACCOUNT_DELETED' => 'compte WhatsApp Business supprimé',
            'ACCOUNT_OFFBOARDED' => 'changement d\'appareil ou réenregistrement du numéro',
            'DISABLED_UPDATE' => 'compte désactivé par Meta',
            default => $event,
        };
    }

    private function graphUrl(string $path): string
    {
        return rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/'.$path;
    }
}
