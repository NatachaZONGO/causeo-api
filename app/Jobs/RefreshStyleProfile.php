<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\AI\StyleProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalculer le profil de style d'un business (StyleProfileService::refresh),
 * seulement s'il est toujours dû au moment de l'exécution.
 */
class RefreshStyleProfile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    /** Un seul recalcul en file par business. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $businessId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->businessId;
    }

    public function handle(StyleProfileService $styles): void
    {
        $business = Business::find($this->businessId);

        if ($business === null || ! $styles->isDue($business)) {
            return;
        }

        try {
            $styles->refresh($business);
        } catch (Throwable $e) {
            Log::warning('RefreshStyleProfile: profil de style non mis à jour.', [
                'business_id' => $this->businessId,
                'exception' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
