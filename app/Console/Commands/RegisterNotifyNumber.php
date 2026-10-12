<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\CauseoNotifyClient;
use Illuminate\Console\Command;

/**
 * Enregistrer le numéro Causeo des alertes sur la Cloud API. Aucun accès à la base :
 * la commande peut être lancée en local avec WHATSAPP_TOKEN et CAUSEO_NOTIFY_PHONE_NUMBER_ID.
 */
class RegisterNotifyNumber extends Command
{
    protected $signature = 'whatsapp:register-notify-number
        {--pin= : PIN à 6 chiffres de la vérification en deux étapes du numéro}';

    protected $description = 'Enregistre le numéro Causeo des alertes aux gérants sur la Cloud API (POST /{phone_number_id}/register)';

    public function handle(CauseoNotifyClient $client): int
    {
        $pin = (string) $this->option('pin');

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            $this->error('Indiquez un PIN de 6 chiffres : --pin=123456');

            return self::INVALID;
        }

        if (! $client->isConfigured()) {
            $this->error('WHATSAPP_TOKEN et CAUSEO_NOTIFY_PHONE_NUMBER_ID doivent être définis.');

            return self::FAILURE;
        }

        $this->line("Enregistrement du numéro {$client->phoneNumberId()}…");
        $result = $client->register($pin);

        if ($result->ok) {
            $this->info('Numéro enregistré. Réponse de Meta : '.json_encode($result->response, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->error("Meta a refusé l'enregistrement (HTTP {$result->status}) :");
        $this->line((string) json_encode($result->error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
