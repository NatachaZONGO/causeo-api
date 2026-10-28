<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminAccessLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\ManagerReply;
use App\Services\AI\LearningService;
use App\Services\AI\ManagerReplyService;
use App\Services\Conversations\ConversationListQuery;
use App\Services\WhatsApp\WhatsAppNotConnectedException;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /** Messages renvoyés par page (GET /conversations/{id}), du plus ancien au plus récent. */
    public const MESSAGES_PER_PAGE = 50;

    /** Un admin qui garde une conversation ouverte (rafraîchie toutes les quelques secondes) n'est journalisé qu'une fois par période. */
    public const ADMIN_VIEW_LOG_MINUTES = 30;

    public function __construct(
        private readonly LearningService $learningService,
        private readonly ManagerReplyService $managerReplies,
        private readonly WhatsAppServiceFactory $whatsAppFactory,
    ) {
    }

    /**
     * Lister les conversations d'une entreprise : q (nom, numéro, texte d'un message),
     * filter (all, unread, pending, orders). Chaque conversation porte son dernier
     * message, ses escalades en attente (la plus récente d'abord), unread_count et
     * has_orders.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = self::validateList($request);

        $conversations = ConversationListQuery::apply($business->conversations(), $data['q'] ?? null, $data['filter'] ?? null)
            ->paginate(30);

        return response()->json($conversations);
    }

    /**
     * Afficher une conversation, ses escalades en attente et une page de messages : les
     * plus récents, ou ceux d'avant le message `before` (chargement en remontant).
     * has_older_messages indique s'il en reste avant. Lecture seule pour un admin,
     * journalisée (admin_access_logs).
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $isOwner = $conversation->business !== null && $conversation->business->user_id === auth()->id();
        abort_if(! $isOwner && ! auth()->user()?->is_admin, 403, 'Cette entreprise ne vous appartient pas.');

        $data = $request->validate([
            // uuid : sous PostgreSQL, une autre valeur ferait échouer la requête (erreur 500).
            'before' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'before.uuid' => 'Identifiant de message invalide.',
            'limit.max' => 'Au plus 100 messages par page.',
        ]);

        $limit = (int) ($data['limit'] ?? self::MESSAGES_PER_PAGE);
        $messages = $conversation->messages()
            ->when($data['before'] ?? null, function ($query, string $before) use ($conversation) {
                $cursor = $conversation->messages()->whereKey($before)->first(['id', 'created_at']);
                abort_if($cursor === null, 404, 'Ce message est introuvable dans la conversation.');

                $query->where(fn ($q) => $q
                    ->where('created_at', '<', $cursor->created_at)
                    ->orWhere(fn ($q) => $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id)));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $conversation->load(['escalations' => fn ($query) => $query->where('status', Escalation::PENDING)->latest()]);
        $conversation->setRelation('messages', $messages->take($limit)->reverse()->values());
        $conversation->setAttribute('has_older_messages', $messages->count() > $limit);

        if (! $isOwner) {
            $this->logAdminView($conversation);
        }

        return response()->json([
            'conversation' => $conversation,
        ]);
    }

    /**
     * Le gérant a ouvert la conversation : ses messages entrants ne sont plus non lus.
     */
    public function read(Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $this->checkOwnership($conversation->business);

        $conversation->markAsRead();

        return response()->json([
            'last_read_at' => $conversation->last_read_at?->toIso8601String(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Mettre l'assistant en pause dans cette conversation : pendant `hours` heures, ou
     * jusqu'à réactivation manuelle sans `hours`. Les messages du client restent enregistrés.
     */
    public function pauseAi(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $this->checkOwnership($conversation->business);

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ], [
            'hours.integer' => 'La durée de la pause doit être un nombre d\'heures.',
            'hours.min' => 'La pause dure au moins 1 heure.',
            'hours.max' => 'La pause dure au plus 720 heures (30 jours) ; sans durée, elle dure jusqu\'à la réactivation.',
        ]);

        $conversation->pauseAi(isset($data['hours']) ? now()->addHours((int) $data['hours']) : null);

        return response()->json([
            'message' => isset($data['hours'])
                ? "L'assistant est en pause pour cette conversation pendant {$data['hours']} h."
                : "L'assistant est en pause pour cette conversation jusqu'à ce que vous le réactiviez.",
        ] + self::pauseState($conversation));
    }

    /**
     * Réactiver l'assistant dans cette conversation. Les messages reçus pendant la pause
     * restent sans réponse automatique.
     */
    public function resumeAi(Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing('business');

        $this->checkOwnership($conversation->business);

        $conversation->resumeAi();

        return response()->json([
            'message' => "L'assistant répond de nouveau dans cette conversation.",
        ] + self::pauseState($conversation));
    }

    /**
     * @return array{ai_paused: bool, ai_paused_until: ?string, ai_paused_indefinitely: bool}
     */
    private static function pauseState(Conversation $conversation): array
    {
        return [
            'ai_paused' => $conversation->ai_paused,
            'ai_paused_until' => $conversation->ai_paused_until?->toIso8601String(),
            'ai_paused_indefinitely' => $conversation->ai_paused_indefinitely,
        ];
    }

    /**
     * Paramètres de la liste des conversations (gérant et admin).
     *
     * @return array{q?: ?string, filter?: ?string}
     */
    public static function validateList(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(ConversationListQuery::FILTERS)],
        ], [
            'q.max' => 'La recherche ne doit pas dépasser 100 caractères.',
            'filter.in' => 'Le filtre doit être l\'un des suivants : '.implode(', ', ConversationListQuery::FILTERS).'.',
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

        // Envoyer depuis le numéro de l'entreprise, jamais depuis le numéro global :
        // sans numéro connecté, on refuse avant d'enregistrer quoi que ce soit.
        try {
            $whatsApp = $this->whatsAppFactory->forBusiness($conversation->business);
        } catch (WhatsAppNotConnectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'human',
            'content' => $data['response'],
            'status' => 'answered_by_human',
        ]);

        $sent = $whatsApp->sendMessage($conversation->customer_phone, $data['response']);

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

        // Le gérant a répondu : il a lu la conversation et prend la main (pause de l'assistant).
        $conversation->markAsRead();
        $conversation->pauseAfterManagerMessage($conversation->business);

        // Exemple de ton et matière du profil de style (si le gérant l'accepte).
        $this->managerReplies->record($conversation->business, $message, ManagerReply::SOURCE_DASHBOARD);

        // Ferme toutes les escalades en attente de la conversation ; apprend de celle
        // à laquelle le gérant a répondu (sinon de la plus récente).
        $this->learningService->answerPendingEscalation($conversation, $data['response'], target: $escalation);

        return response()->json([
            'message' => 'Votre réponse a été envoyée au client.',
            'data' => $message,
        ]);
    }

    /**
     * Qui a ouvert quelle conversation, et quand ; jamais son contenu. Au plus une entrée
     * par admin et par conversation sur ADMIN_VIEW_LOG_MINUTES.
     */
    private function logAdminView(Conversation $conversation): void
    {
        $recent = AdminAccessLog::query()
            ->where('admin_id', auth()->id())
            ->where('action', AdminAccessLog::CONVERSATION_VIEWED)
            ->where('conversation_id', $conversation->id)
            ->where('created_at', '>=', now()->subMinutes(self::ADMIN_VIEW_LOG_MINUTES))
            ->exists();

        if (! $recent) {
            AdminAccessLog::create([
                'admin_id' => auth()->id(),
                'action' => AdminAccessLog::CONVERSATION_VIEWED,
                'conversation_id' => $conversation->id,
                'business_id' => $conversation->business_id,
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
