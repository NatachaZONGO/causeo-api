<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\CauseoNotifyClient;
use App\Services\WhatsApp\CauseoNotifyResult;
use Illuminate\Console\Command;

/**
 * Enregistrer le numéro Causeo des alertes sur la Cloud API et abonner l'app aux
 * webhooks de son WABA. Aucun accès à la base : la commande peut être lancée en local
 * avec WHATSAPP_TOKEN, CAUSEO_NOTIFY_PHONE_NUMBER_ID et CAUSEO_NOTIFY_WABA_ID.
 */
class RegisterNotifyNumber extends Command
{
    /** Délai d'attente (secondes) des appels à Meta : l'enregistrement peut être long. */
    public const TIMEOUT = 60;

    protected $signature = 'whatsapp:register-notify-number
        {--pin= : PIN à 6 chiffres de la vérification en deux étapes du numéro (sans PIN, seul l\'abonnement aux webhooks est vérifié)}';

    protected $description = 'Enregistre le numéro Causeo des alertes aux gérants sur la Cloud API (POST /{phone_number_id}/register) et abonne l\'app aux webhooks de son WABA (POST /{waba_id}/subscribed_apps)';

    public function handle(CauseoNotifyClient $client): int
    {
        $pin = $this->option('pin');

        if ($pin !== null && preg_match('/^\d{6}$/', (string) $pin) !== 1) {
            $this->error('Indiquez un PIN de 6 chiffres : --pin=123456');

            return self::INVALID;
        }

        if (! $client->isConfigured()) {
            $this->error('WHATSAPP_TOKEN et CAUSEO_NOTIFY_PHONE_NUMBER_ID doivent être définis.');

            return self::FAILURE;
        }

        $client = $client->withTimeout(self::TIMEOUT);

        if ($pin === null) {
            $this->line('Pas de PIN : enregistrement du numéro non relancé.');
        } else {
            $this->line("Enregistrement du numéro {$client->phoneNumberId()}…");
            $result = $client->register((string) $pin);

            if (! $result->ok) {
                return $this->refused("Meta a refusé l'enregistrement", $result);
            }

            $this->info('Numéro enregistré. Réponse de Meta : '.json_encode($result->response, JSON_UNESCAPED_UNICODE));
        }

        return $this->subscribe($client);
    }

    /**
     * Sans abonnement de l'app au WABA, Meta n'envoie aucun statut des alertes
     * (sent, delivered, read, failed) : impossible de savoir pourquoi une alerte n'arrive pas.
     */
    private function subscribe(CauseoNotifyClient $client): int
    {
        if ($client->wabaId() === '') {
            $this->error('CAUSEO_NOTIFY_WABA_ID doit être défini pour abonner l\'app aux webhooks du WABA.');

            return self::FAILURE;
        }

        $this->line("Abonnement de l'app aux webhooks du WABA {$client->wabaId()}…");
        $result = $client->subscribeApp();

        if (! $result->ok) {
            return $this->refused("Meta a refusé l'abonnement aux webhooks", $result);
        }

        $apps = $client->subscribedApps();

        if (! $apps->ok) {
            return $this->refused('Lecture des apps abonnées impossible', $apps);
        }

        $names = array_map(
            fn ($app) => (string) (data_get($app, 'whatsapp_business_api_data.name') ?: data_get($app, 'whatsapp_business_api_data.id') ?: '?'),
            (array) ($apps->response['data'] ?? []),
        );

        if ($names === []) {
            $this->error("Meta n'indique aucune app abonnée aux webhooks du WABA.");

            return self::FAILURE;
        }

        $this->info('App abonnée aux webhooks du WABA. Apps abonnées : '.implode(', ', $names));

        return self::SUCCESS;
    }

    private function refused(string $message, CauseoNotifyResult $result): int
    {
        $this->error("{$message} (HTTP {$result->status}) :");
        $this->line((string) json_encode($result->error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
