<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\WhatsApp\OwnerAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envoyer une alerte WhatsApp au gérant. La file ne contient que des identifiants :
 * le numéro et le contenu sont relus à l'envoi, et jamais écrits dans les logs.
 */
class SendOwnerWhatsAppAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Durée (jours) pendant laquelle un message envoyé reste relié à son business, pour les statuts de Meta. */
    public const SENT_MESSAGE_TTL_DAYS = 7;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $businessId,
    ) {
        $this->afterCommit();
    }

    public function handle(OwnerAlertService $alerts): void
    {
        $template = OwnerAlertService::TEMPLATES[$this->kind] ?? null;
        $owner = Business::with('user')->find($this->businessId)?->user;

        // Le gérant a pu désactiver les alertes entre-temps.
        if ($template === null || ! $alerts->isConfigured() || ! OwnerAlertService::ownerAccepts($owner)) {
            return;
        }

        $parameters = $alerts->parametersFor($this->kind, $this->id);

        if ($parameters === null) {
            return;
        }

        $result = $alerts->send($owner, $template, $parameters);
        $context = ['business_id' => $this->businessId, 'kind' => $this->kind, 'template' => $template, 'attempt' => $this->attempts()];

        if ($result->ok) {
            $messageId = $result->response['messages'][0]['id'] ?? null;
            Log::info('Alerte WhatsApp envoyée au gérant.', $context + ['message_id' => $messageId]);

            // Le webhook des statuts ne donne que l'identifiant du message : il retrouve ainsi le business.
            if (is_string($messageId) && $messageId !== '') {
                Cache::put(self::sentMessageKey($messageId), ['business_id' => $this->businessId, 'kind' => $this->kind, 'template' => $template], now()->addDays(self::SENT_MESSAGE_TTL_DAYS));
            }

            return;
        }

        if ($result->retryable()) {
            // Message sans numéro ni contenu : il finit dans failed_jobs après le dernier essai.
            throw new RuntimeException("Envoi de l'alerte WhatsApp {$template} refusé temporairement par Meta (HTTP {$result->status}).");
        }

        // Modèle pas encore approuvé, numéro invalide… : un nouvel essai n'y changerait rien.
        Log::warning('Alerte WhatsApp au gérant refusée par Meta.', $context + $result->errorForLog());
    }

    public static function sentMessageKey(string $messageId): string
    {
        return "whatsapp-alerts:message:{$messageId}";
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Alerte WhatsApp au gérant abandonnée après plusieurs essais.', [
            'business_id' => $this->businessId,
            'kind' => $this->kind,
            'template' => OwnerAlertService::TEMPLATES[$this->kind] ?? null,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
