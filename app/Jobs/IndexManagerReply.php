<?php

namespace App\Jobs;

use App\Models\ManagerReply;
use App\Services\AI\ManagerReplyService;
use App\Services\AI\StyleProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Indexer une réponse du gérant (embedding de la question du client), puis
 * demander un nouveau profil de style s'il est dû.
 */
class IndexManagerReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $managerReplyId,
    ) {
        $this->afterCommit();
    }

    public function handle(ManagerReplyService $replies, StyleProfileService $styles): void
    {
        $reply = ManagerReply::with('business')->find($this->managerReplyId);

        if ($reply === null || $reply->business === null) {
            return;
        }

        try {
            $replies->index($reply);
        } catch (Throwable $e) {
            // Sans embedding, la réponse ne sert pas d'exemple mais compte pour le profil.
            Log::warning('IndexManagerReply: embedding de la question non enregistré.', [
                'business_id' => $reply->business_id,
                'manager_reply_id' => $reply->id,
                'exception' => $e::class,
            ]);
        }

        if ($styles->isDue($reply->business)) {
            RefreshStyleProfile::dispatch($reply->business_id);
        }
    }
}
