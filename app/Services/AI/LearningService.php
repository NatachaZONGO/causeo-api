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
     * Une réponse humaine ferme TOUTES les escalades en attente de la conversation
     * (et $target, l'escalade à laquelle le gérant a répondu depuis le dashboard). Seule
     * la plus récente est apprise : $target, sinon la dernière en attente créée dans les
     * dernières 24 h ($learn : faux pour une réponse vocale du gérant, sans texte).
     * Ne fait rien s'il n'y a aucune escalade à fermer.
     */
    public function answerPendingEscalation(Conversation $conversation, string $response, bool $learn = true, ?Escalation $target = null): void
    {
        $pending = $conversation->escalations()
            ->where('status', Escalation::PENDING)
            ->latest()
            ->get();

        if ($target !== null && ! $pending->contains('id', $target->id)) {
            $pending->prepend($target);
        }

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending as $escalation) {
            $escalation->update([
                'human_response' => $response,
                'status' => Escalation::ANSWERED,
                'answered_at' => now(),
            ]);
        }

        // Instance mise à jour ci-dessus (avec human_response), pas $target tel que reçu.
        $learned = $target !== null
            ? $pending->firstWhere('id', $target->id)
            : $pending->first(fn (Escalation $escalation) => $escalation->created_at->gte(now()->subDay()));

        if ($learn && $learned !== null) {
            $this->learnFromEscalation($learned);
        }
    }

    /**
     * Apprendre d'une escalade répondue en créant une réponse apprise vectorisée.
     */
    public function learnFromEscalation(Escalation $escalation): void
    {
        // Vocal non transcrit, document, preuve de paiement… : la « question » n'est que le motif.
        if ($escalation->hasNonTextQuestion()) {
            return;
        }

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
