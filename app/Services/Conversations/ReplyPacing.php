<?php

namespace App\Services\Conversations;

use Carbon\CarbonInterface;

/**
 * Rythme des réponses WhatsApp du bot (config services.reply_pacing) : regroupement
 * des messages du client, puis délai « en train d'écrire » avant l'envoi.
 */
class ReplyPacing
{
    /**
     * Attente avant de répondre au message qui vient d'arriver : debounce_seconds,
     * sans dépasser max_wait_seconds après le premier message encore sans réponse.
     */
    public function groupingDelay(CarbonInterface $firstPendingAt): int
    {
        $remaining = $this->maxWait() - $this->secondsSince($firstPendingAt);

        return max(0, min($this->debounce(), $remaining));
    }

    /**
     * Le premier message sans réponse attend depuis max_wait_seconds : on répond sans
     * attendre la fin de la rafale (une seconde de marge : les délais de la file sont entiers).
     */
    public function maxWaitReached(CarbonInterface $firstPendingAt): bool
    {
        return $this->secondsSince($firstPendingAt) >= $this->maxWait() - 1;
    }

    /**
     * Temps qu'un humain mettrait à écrire ce texte : typing_base_seconds + 1 s par
     * typing_chars_per_second caractères, ± typing_jitter_seconds, plafonné à typing_max_seconds.
     */
    public function typingSeconds(string $text): float
    {
        $config = config('services.reply_pacing');
        $jitter = (float) ($config['typing_jitter_seconds'] ?? 0);

        $seconds = (float) ($config['typing_base_seconds'] ?? 3)
            + mb_strlen($text) / max(1, (int) ($config['typing_chars_per_second'] ?? 40))
            + ($jitter > 0 ? (mt_rand() / mt_getrandmax() * 2 - 1) * $jitter : 0);

        return max(0.0, min((float) ($config['typing_max_seconds'] ?? 20), $seconds));
    }

    private function debounce(): int
    {
        return max(0, (int) config('services.reply_pacing.debounce_seconds', 8));
    }

    private function maxWait(): int
    {
        return max(0, (int) config('services.reply_pacing.max_wait_seconds', 30));
    }

    private function secondsSince(CarbonInterface $at): int
    {
        return max(0, now()->getTimestamp() - $at->getTimestamp());
    }
}
