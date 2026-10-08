<?php

namespace Tests\Support;

use App\Models\Business;
use App\Services\AI\AIResponseService;
use App\Services\AppointmentService;
use App\Services\Embedding\EmbeddingService;
use App\Services\OrderService;
use Illuminate\Support\Collection;

/**
 * Service réel, avec la recherche RAG (pgvector) remplacée par un contexte fixe.
 */
class FixedContextAIResponseService extends AIResponseService
{
    public function __construct(private readonly string $fixedContext)
    {
        parent::__construct(app(EmbeddingService::class), app(OrderService::class), app(AppointmentService::class));
    }

    public function findRelevantContext(Business $business, string $question, int $limit = 5): Collection
    {
        return collect([(object) ['id' => 'chunk-1', 'content' => $this->fixedContext, 'similarity' => 0.9]]);
    }

    public function findRelevantMedia(Business $business, string $question, int $limit = 3): Collection
    {
        return collect();
    }
}
