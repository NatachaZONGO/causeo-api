<?php

namespace App\Services\AI;

use App\Models\Conversation;
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
     * Rattacher une réponse humaine à la dernière escalade en attente de la
     * conversation (créée dans les dernières 24 h), puis en apprendre.
     * Ne fait rien s'il n'y en a pas.
     */
    public function answerPendingEscalation(Conversation $conversation, string $response): void
    {
        $escalation = $conversation->escalations()
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->first();

        if ($escalation === null) {
            return;
        }

        $escalation->update([
            'human_response' => $response,
            'status' => 'answered',
            'answered_at' => now(),
        ]);

        $this->learnFromEscalation($escalation);
    }

    /**
     * Apprendre d'une escalade répondue en créant une réponse apprise vectorisée.
     */
    public function learnFromEscalation(Escalation $escalation): void
    {
        // En Gratuit, les réponses du gérant ne sont pas apprises (celles déjà apprises restent utilisées).
        $business = $escalation->business;
        if ($business !== null && ! $business->billingState()->plan->learning) {
            Log::info('LearningService: apprentissage non compris dans la formule, réponse non apprise.', [
                'business_id' => $business->id,
                'escalation_id' => $escalation->id,
            ]);

            return;
        }

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
