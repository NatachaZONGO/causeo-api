<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\LearnedResponse;
use App\Services\Embedding\EmbeddingService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Lister les conversations d'une entreprise.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $conversations = $business->conversations()
            ->with(['escalations' => fn ($q) => $q->where('status', 'pending')])
            ->orderByDesc('last_message_at')
            ->paginate(15);

        $conversations->getCollection()->transform(function ($conversation) {
            $lastMsg = $conversation->messages()->orderByDesc('created_at')->first();
            $conversation->setAttribute('latest_message', $lastMsg);

            return $conversation;
        });

        return response()->json($conversations);
    }

    /**
     * Afficher une conversation avec ses messages et escalations en attente.
     */
    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $this->checkOwnership($conversation->business);

        $conversation->load([
            'messages' => fn ($query) => $query->orderBy('created_at'),
            'escalations' => fn ($query) => $query->where('status', 'pending'),
        ]);

        return response()->json([
            'conversation' => $conversation,
        ]);
    }

    /**
     * Réponse manuelle du gérant à une conversation.
     */
    public function manualReply(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $this->checkOwnership($conversation->business);

        $data = $request->validate([
            'response' => ['required', 'string'],
            'escalation_id' => ['nullable', 'exists:escalations,id'],
        ], [
            'response.required' => 'La réponse est obligatoire.',
            'response.string' => 'La réponse doit être une chaîne de caractères.',
            'escalation_id.exists' => 'L\'escalation indiquée est introuvable.',
        ]);

        $message = $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'human',
            'content' => $data['response'],
            'status' => 'answered_by_human',
        ]);

        $sent = $this->whatsApp->sendMessage($conversation->customer_phone, $data['response']);

        if (! empty($sent['messages'][0]['id'])) {
            $message->update(['whatsapp_message_id' => $sent['messages'][0]['id']]);
        }

        if (! empty($data['escalation_id'])) {
            $escalation = Escalation::findOrFail($data['escalation_id']);

            $escalation->update([
                'human_response' => $data['response'],
                'status' => 'answered',
                'answered_at' => now(),
            ]);

            $this->learnFromReply($escalation);
        }

        return response()->json([
            'message' => 'Votre réponse a été envoyée au client.',
            'data' => $message,
        ]);
    }

    /**
     * Apprendre d'une réponse manuelle en créant une réponse apprise vectorisée.
     */
    private function learnFromReply(Escalation $escalation): void
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
            Log::error('ConversationController::learnFromReply a échoué', [
                'escalation_id' => $escalation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
