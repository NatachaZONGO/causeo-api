<?php

namespace App\Services\WhatsApp;

/**
 * Réponse de Meta à un appel du numéro Causeo.
 */
final class CauseoNotifyResult
{
    /**
     * @param  int  $status  code HTTP (0 si Meta n'a pas pu être joint)
     * @param  array<string, mixed>  $response  corps de la réponse de Meta
     * @param  array<string, mixed>|null  $error  objet « error » de Meta, tel quel
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $response,
        public readonly ?array $error = null,
    ) {
    }

    /**
     * Erreur passagère (réseau, limite de débit, panne de Meta) : un nouvel essai a un sens.
     * Un modèle non approuvé ou un numéro invalide (4xx) ne réussira pas mieux plus tard.
     */
    public function retryable(): bool
    {
        return ! $this->ok && ($this->status === 0 || $this->status === 429 || $this->status >= 500);
    }

    /**
     * Résumé de l'erreur pour les logs : codes et message de Meta, sans numéro de téléphone.
     *
     * @return array<string, mixed>
     */
    public function errorForLog(): array
    {
        $message = (string) ($this->error['message'] ?? '');

        return array_filter([
            'status' => $this->status,
            'error_code' => $this->error['code'] ?? null,
            'error_subcode' => $this->error['error_subcode'] ?? null,
            'error_type' => $this->error['type'] ?? null,
            'error_message' => $message !== '' ? mb_substr(preg_replace('/\+?\d[\d\s.-]{6,}\d/', '[numéro masqué]', $message), 0, 300) : null,
        ], fn ($value) => $value !== null);
    }
}
