<?php

namespace App\Services\AI;

use App\Models\Escalation;
use App\Models\LearnedResponse;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LearningService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Apprendre d'une escalade répondue en créant une réponse apprise vectorisée.
     */
    public function learnFromEscalation(Escalation $escalation): void
    {
        try {
            $learned = LearnedResponse::create([
                'business_id' => $escalation->business_id,
                'escalation_id' => $escalation->id,
                'question' => $escalation->customer_question,
                'answer' => $escalation->human_response,
                'usage_count' => 0,
            ]);

            $vector = '['.implode(',', $this->embeddingService->embed($escalation->customer_question)).']';

            DB::statement(
                'UPDATE learned_responses SET question_embedding = ?::vector WHERE id = ?',
                [$vector, $learned->id],
            );
        } catch (Throwable $e) {
            Log::error('LearningService::learnFromEscalation a échoué', [
                'escalation_id' => $escalation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
