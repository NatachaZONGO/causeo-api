<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Services\AI\LearningService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly LearningService $learningService,
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

        // Charger l'escalade avant tout envoi : un escalation_id invalide
        // renvoie 404 sans que le client ne reçoive de message.
        $escalation = ! empty($data['escalation_id'])
            ? $conversation->escalations()->findOrFail($data['escalation_id'])
            : null;

        $message = $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'human',
            'content' => $data['response'],
            'status' => 'answered_by_human',
        ]);

        // Envoyer depuis le numéro de l'entreprise, pas depuis la config globale.
        $sent = WhatsAppService::forBusiness($conversation->business)
            ->sendMessage($conversation->customer_phone, $data['response']);

        $wamid = $sent['messages'][0]['id'] ?? null;

        if (empty($wamid)) {
            // Échec Meta : l'escalade reste en attente et rien n'est appris.
            $message->update(['status' => 'failed']);

            return response()->json([
                'message' => 'L\'envoi a échoué, veuillez réessayer.',
                'data' => $message,
            ], 502);
        }

        $message->update(['whatsapp_message_id' => $wamid]);

        if ($escalation !== null) {
            $escalation->update([
                'human_response' => $data['response'],
                'status' => 'answered',
                'answered_at' => now(),
            ]);

            $this->learningService->learnFromEscalation($escalation);
        } else {
            $this->learningService->answerPendingEscalation($conversation, $data['response']);
        }

        return response()->json([
            'message' => 'Votre réponse a été envoyée au client.',
            'data' => $message,
        ]);
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
