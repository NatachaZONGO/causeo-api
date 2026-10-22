<?php

namespace App\Services\AI;

use App\Jobs\IndexManagerReply;
use App\Models\Business;
use App\Models\ManagerReply;
use App\Models\Message;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Réponses écrites par le gérant (dashboard et échos de l'app WhatsApp Business),
 * indexées par l'embedding de la question du client : elles servent d'exemples de
 * ton au bot et de matière au profil de style. Toujours filtrées par business.
 */
class ManagerReplyService
{
    /** Similarité minimale entre la question du client et celle d'un exemple. */
    public const EXAMPLE_SIMILARITY_THRESHOLD = 0.6;

    /** Messages du client repris comme question, au plus, avant la réponse du gérant. */
    private const QUESTION_MAX_MESSAGES = 3;

    private const QUESTION_MAX_LENGTH = 1000;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Garder une réponse du gérant (déjà enregistrée dans messages), si le gérant
     * laisse le bot apprendre de sa façon d'écrire. L'embedding est calculé en file.
     */
    public function record(Business $business, Message $message, string $source): ?ManagerReply
    {
        $reply = trim((string) $message->content);

        if ($reply === '' || ! $business->learnsFromOwner()) {
            return null;
        }

        try {
            $managerReply = ManagerReply::firstOrCreate(['message_id' => $message->id], [
                'business_id' => $business->id,
                'customer_question' => $this->customerQuestion($message),
                'reply' => $reply,
                'source' => $source,
            ]);
        } catch (Throwable $e) {
            // Pas de getMessage() : le SQL contiendrait le texte des messages.
            Log::error('ManagerReplyService: réponse du gérant non enregistrée.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            return null;
        }

        if ($managerReply->wasRecentlyCreated) {
            IndexManagerReply::dispatch($managerReply->id);
        }

        return $managerReply;
    }

    /**
     * Enregistrer l'embedding de la question du client (pgvector).
     */
    public function index(ManagerReply $reply): void
    {
        if ($reply->customer_question === null || $reply->customer_question === '') {
            return;
        }

        $vector = '['.implode(',', $this->embeddingService->embed($reply->customer_question)).']';

        DB::statement('UPDATE manager_replies SET question_embedding = ?::vector WHERE id = ?', [$vector, $reply->id]);
    }

    /**
     * Réponses du gérant aux questions les plus proches, pour le ton.
     *
     * @param  string  $vector  embedding de la question au format pgvector ([x,y,…])
     * @return Collection<int, object{customer_question: string, reply: string, similarity: float}>
     */
    public function similarExamples(Business $business, string $vector, int $limit = 3): Collection
    {
        return collect(DB::select(
            'SELECT customer_question, reply, 1 - (question_embedding <=> ?::vector) AS similarity
             FROM manager_replies
             WHERE business_id = ? AND question_embedding IS NOT NULL
             ORDER BY question_embedding <=> ?::vector
             LIMIT ?',
            [$vector, $business->id, $vector, $limit],
        ))->filter(fn ($row) => (float) $row->similarity >= self::EXAMPLE_SIMILARITY_THRESHOLD)->values();
    }

    /**
     * Reprendre les réponses du gérant déjà en base (avant cette fonctionnalité).
     * Les réponses envoyées pour un rendez-vous (texte généré) ne sont pas reprises.
     *
     * @return int nombre de réponses ajoutées
     */
    public function backfill(Business $business, int $limit = 200): int
    {
        if (! $business->learnsFromOwner()) {
            return 0;
        }

        $messages = Message::query()
            ->whereIn('conversation_id', $business->conversations()->select('id'))
            ->where('direction', 'outbound')
            ->where('sender_type', 'human')
            ->where('status', 'answered_by_human')
            ->whereNotIn('id', ManagerReply::query()->whereNotNull('message_id')->select('message_id'))
            ->latest()
            ->limit($limit)
            ->get();

        $added = 0;
        foreach ($messages as $message) {
            if ($this->record($business, $message, ManagerReply::SOURCE_HISTORY)?->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Derniers messages du client avant la réponse, depuis la précédente réponse
     * envoyée dans la conversation (au plus QUESTION_MAX_MESSAGES).
     */
    private function customerQuestion(Message $message): ?string
    {
        $previousOutbound = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'outbound')
            ->where('id', '!=', $message->id)
            ->where('created_at', '<=', $message->created_at)
            ->max('created_at');

        $question = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'inbound')
            ->where('created_at', '<=', $message->created_at)
            ->when($previousOutbound !== null, fn ($query) => $query->where('created_at', '>', $previousOutbound))
            ->orderByDesc('created_at')
            ->limit(self::QUESTION_MAX_MESSAGES)
            ->pluck('content')
            ->reverse()
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->implode("\n");

        return $question !== '' ? mb_substr($question, 0, self::QUESTION_MAX_LENGTH) : null;
    }
}
