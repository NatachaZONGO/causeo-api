<?php

namespace App\Services\Billing;

use Carbon\CarbonInterface;

/**
 * Consommation des réponses automatiques d'un business sur la fenêtre de sa formule.
 *
 * window : day (Gratuit, remis à zéro à minuit à Ouagadougou), period (période
 * payée ou essai) ou null (formule sans limite).
 */
final readonly class Usage
{
    public function __construct(
        public ?int $limit,
        public int $used,
        public ?string $window,
        public ?CarbonInterface $startsAt,
        public ?CarbonInterface $resetsAt,
    ) {
    }

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    public function isExhausted(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    public function percent(): ?int
    {
        if ($this->limit === null || $this->limit === 0) {
            return null;
        }

        return (int) floor($this->used * 100 / $this->limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'percent' => $this->percent(),
            'window' => $this->window,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'resets_at' => $this->resetsAt?->toIso8601String(),
        ];
    }
}
