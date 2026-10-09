<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Services\AI\AIResponseService;
use App\Services\AI\LearningService;
use App\Services\Billing\UsageService;
use App\Services\WhatsApp\WhatsAppAccountService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    /**
     * Événements account_update qui coupent la liaison avec Causeo (DISABLED_UPDATE
     * n'en fait partie que pour l'état DISABLE). Référence :
     * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update/
     */
    private const DISCONNECTING_ACCOUNT_EVENTS = ['PARTNER_REMOVED', 'PARTNER_APP_UNINSTALLED', 'ACCOUNT_DELETED', 'ACCOUNT_OFFBOARDED'];

    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly AIResponseService $ai,
        private readonly LearningService $learningService,
        private readonly WhatsAppAccountService $accounts,
        private readonly UsageService $usage,
    ) {
    }

    /**
     * Vérification du webhook WhatsApp (handshake Meta).
     */
    public function verify(Request $request): Response
    {
        if ($request->query('hub_verify_token') === config('services.whatsapp.verify_token')) {
            return response($request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Réception des messages entrants WhatsApp.
     *
     * Répond 200 immédiatement (avec Content-Length, voir acknowledge()) puis
     * traite le message après la réponse, pour éviter que Meta ne réémette le
     * webhook (timeout ~5 s) et ne provoque des réponses en double.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        $field = data_get($payload, 'entry.0.changes.0.field');

        if ($field === 'account_update') {
            $this->handleAccountUpdates($request, $payload);

            return $this->acknowledge();
        }

        if ($field !== 'messages') {
            $value = data_get($payload, 'entry.0.changes.0.value');

            $this->handleNonMessageEvent(is_string($field) ? $field : null, is_array($value) ? $value : []);

            return $this->acknowledge();
        }

        $incoming = $this->whatsApp->parseIncomingMessage($payload);

        if ($incoming === null || $incoming['message_id'] === '') {
            return $this->acknowledge();
        }

        // Déduplication : si ce message entrant a déjà été enregistré, on l'ignore.
        $alreadySeen = Message::where('whatsapp_message_id', $incoming['message_id'])
            ->where('direction', 'inbound')
            ->exists();

        if ($alreadySeen) {
            Log::info('WebhookController: message WhatsApp déjà reçu, ignoré (doublon).', [
                'whatsapp_message_id' => $incoming['message_id'],
            ]);

            return $this->acknowledge();
        }

        $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        // Traitement lourd (IA + envois WhatsApp) exécuté après l'envoi de la réponse 200.
        dispatch(function () use ($incoming, $phoneNumberId): void {
            $this->process($incoming, $phoneNumberId);
        })->afterResponse();

        return $this->acknowledge();
    }

    /**
     * Accusé de réception pour Meta. Le Content-Length est indispensable : sous
     * php artisan serve, sans longueur annoncée, Meta attendrait la fermeture de
     * la connexion, donc la fin du traitement lancé avec afterResponse().
     */
    private function acknowledge(): Response
    {
        return response('', 200)->header('Content-Length', '0');
    }

    /**
     * Traiter les événements account_update (signés par Meta) : certains indiquent
     * que le client a retiré l'accès de Causeo ou que son compte n'est plus utilisable.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleAccountUpdates(Request $request, array $payload): void
    {
        // Un faux account_update suffirait à déconnecter un business : on exige la signature Meta.
        if (! $this->hasValidMetaSignature($request)) {
            Log::warning('WebhookController: account_update ignoré, signature Meta absente ou invalide.');

            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) === 'account_update' && is_array($change['value'] ?? null)) {
                    $this->handleAccountUpdate(isset($entry['id']) ? (string) $entry['id'] : null, $change['value']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleAccountUpdate(?string $entryId, array $value): void
    {
        $event = (string) ($value['event'] ?? '');
        $banState = data_get($value, 'ban_info.waba_ban_state');

        // Le WABA concerné est dans waba_info quand Meta le fournit (il peut différer de entry.id).
        $wabaId = (string) (data_get($value, 'waba_info.waba_id') ?: $entryId);

        $disconnects = in_array($event, self::DISCONNECTING_ACCOUNT_EVENTS, true)
            || ($event === 'DISABLED_UPDATE' && $banState === 'DISABLE');

        if (! $disconnects) {
            Log::info('WebhookController: account_update reçu, sans effet.', [
                'event' => $event,
                'waba_id' => $wabaId,
                'ban_state' => $banState,
            ]);

            return;
        }

        $businesses = $wabaId !== '' ? Business::where('whatsapp_waba_id', $wabaId)->get() : collect();

        if ($businesses->isEmpty()) {
            Log::info('WebhookController: account_update pour un WABA sans business connecté, ignoré.', [
                'event' => $event,
                'waba_id' => $wabaId,
            ]);

            return;
        }

        $reason = data_get($value, 'disconnection_info.reason');
        $initiatedBy = data_get($value, 'disconnection_info.initiated_by');
        $detail = $reason ? "motif : {$reason}".($initiatedBy ? ", à l'initiative de : {$initiatedBy}" : '') : null;

        foreach ($businesses as $business) {
            $this->accounts->disconnectFromMeta($business, $event, $detail);

            Log::warning('WebhookController: business déconnecté de WhatsApp par Meta.', [
                'business_id' => $business->id,
                'event' => $event,
                'waba_id' => $wabaId,
                'disconnection_reason' => $reason,
            ]);
        }
    }

    /**
     * Vérifier l'en-tête X-Hub-Signature-256 (HMAC SHA-256 du corps brut avec le secret de l'app).
     */
    private function hasValidMetaSignature(Request $request): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    /**
     * Traiter les événements webhook autres que `messages` (Coexistence),
     * sans réponse IA. Les fields inconnus sont ignorés.
     *
     * @param  array<string, mixed>  $value
     */
    private function handleNonMessageEvent(?string $field, array $value): void
    {
        match ($field) {
            'smb_message_echoes' => $this->recordMessageEchoes($value),
            'history', 'smb_app_state_sync' => Log::info("WebhookController: événement {$field} reçu.", [
                'phone_number_id' => data_get($value, 'metadata.phone_number_id'),
            ]),
            default => null,
        };
    }

    /**
     * Enregistrer les échos (messages envoyés par le gérant depuis l'app
     * WhatsApp Business) comme des réponses humaines dans la conversation.
     * Seuls les échos texte sont pris en compte pour l'instant.
     *
     * @param  array<string, mixed>  $value
     */
    private function recordMessageEchoes(array $value): void
    {
        $phoneNumberId = data_get($value, 'metadata.phone_number_id');

        if (empty($phoneNumberId)) {
            return;
        }

        $business = $this->connectedBusiness($phoneNumberId);

        if ($business === null) {
            return;
        }

        $recorded = 0;

        try {
            foreach ($value['message_echoes'] ?? [] as $echo) {
                if (! is_array($echo) || ($echo['type'] ?? null) !== 'text') {
                    continue;
                }

                $to = $echo['to'] ?? '';
                $messageId = $echo['id'] ?? '';
                $text = $echo['text']['body'] ?? '';

                if ($to === '' || $messageId === '' || $text === '') {
                    continue;
                }

                // Déduplication : Meta peut réémettre le webhook.
                if (Message::where('whatsapp_message_id', $messageId)->exists()) {
                    continue;
                }

                $conversation = Conversation::firstOrCreate([
                    'business_id' => $business->id,
                    'customer_phone' => $to,
                ]);

                $conversation->messages()->create([
                    'direction' => 'outbound',
                    'sender_type' => 'human',
                    'content' => $text,
                    'status' => 'answered_by_human',
                    'whatsapp_message_id' => $messageId,
                ]);

                // Une erreur d'apprentissage ne doit pas bloquer les échos suivants.
                try {
                    $this->learningService->answerPendingEscalation($conversation, $text);
                } catch (Throwable $e) {
                    Log::error('WebhookController: apprentissage depuis un écho échoué', [
                        'business_id' => $business->id,
                        'exception' => $e::class,
                    ]);
                }

                $conversation->update(['last_message_at' => now()]);

                $recorded++;
            }
        } catch (Throwable $e) {
            // Pas de getMessage() : une QueryException contient le SQL avec
            // les valeurs (texte et numéro du client).
            Log::error('WebhookController::recordMessageEchoes a échoué', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);
        }

        Log::info('WebhookController: échos enregistrés.', [
            'field' => 'smb_message_echoes',
            'business_id' => $business->id,
            'recorded' => $recorded,
        ]);
    }

    /**
     * Business connecté à ce numéro WhatsApp, ou null (journalisé) : un message
     * pour un numéro inconnu ou déconnecté est ignoré sans erreur.
     */
    private function connectedBusiness(?string $phoneNumberId): ?Business
    {
        $business = empty($phoneNumberId)
            ? null
            : Business::where('whatsapp_phone_number_id', $phoneNumberId)->where('whatsapp_verified', true)->first();

        if ($business === null) {
            Log::info('WebhookController: événement pour un numéro sans business connecté, ignoré.', [
                'phone_number_id' => $phoneNumberId,
            ]);
        }

        return $business;
    }

    /**
     * Traiter effectivement un message entrant : génération de la réponse IA,
     * envoi WhatsApp, escalade éventuelle.
     *
     * @param  array{from: string, message_id: string, text: string, customer_name: ?string}  $incoming
     */
    private function process(array $incoming, ?string $phoneNumberId): void
    {
        try {
            $business = $this->connectedBusiness($phoneNumberId);

            if ($business === null) {
                return;
            }

            $whatsApp = WhatsAppService::forBusiness($business);

            $conversation = Conversation::firstOrCreate(
                [
                    'business_id' => $business->id,
                    'customer_phone' => $incoming['from'],
                ],
                [
                    'customer_name' => $incoming['customer_name'],
                ],
            );

            $conversation->update([
                'customer_name' => $incoming['customer_name'] ?? $conversation->customer_name,
                'last_message_at' => now(),
                'is_active' => true,
            ]);

            try {
                $inboundMessage = $conversation->messages()->create([
                    'direction' => 'inbound',
                    'sender_type' => 'customer',
                    'content' => $incoming['text'],
                    'status' => 'pending',
                    'whatsapp_message_id' => $incoming['message_id'],
                ]);
            } catch (QueryException $e) {
                // Violation de l'index unique : un autre traitement du même
                // webhook (doublon Meta) a déjà pris ce message en charge.
                Log::info('WebhookController: message entrant déjà traité, abandon.', [
                    'whatsapp_message_id' => $incoming['message_id'],
                ]);

                return;
            }

            // Limite de réponses atteinte : le message reste enregistré (et non lu dans
            // l'app WhatsApp Business du gérant), mais l'IA n'est pas appelée.
            if (! $this->usage->allowsReply($business)) {
                $this->usage->recordMissedReply($business, $inboundMessage);

                return;
            }

            $whatsApp->markAsRead($incoming['message_id']);

            $result = $this->ai->answer($business, $incoming['text'], $conversation->id);

            if ($result['should_escalate']) {
                Escalation::create([
                    'conversation_id' => $conversation->id,
                    'message_id' => $inboundMessage->id,
                    'business_id' => $business->id,
                    'customer_question' => $incoming['text'],
                    'status' => 'pending',
                ]);

                $inboundMessage->update(['status' => 'escalated']);

                $waitingMessage = $result['answer']
                    ?? 'Merci pour votre message ! Je vérifie ça et je reviens vers vous très vite 😊';

                $sent = $whatsApp->sendMessage($incoming['from'], $waitingMessage);

                $conversation->messages()->create([
                    'direction' => 'outbound',
                    'sender_type' => 'ai',
                    'content' => $waitingMessage,
                    'status' => 'sent',
                    'confidence_score' => $result['confidence'],
                    'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
                    'metadata' => [
                        'context_used' => $result['context_used'],
                        'escalated' => true,
                    ] + ($result['tool_trace'] ?? []),
                ]);
            } else {
                $sent = $whatsApp->sendMessage($incoming['from'], $result['answer']);

                $conversation->messages()->create([
                    'direction' => 'outbound',
                    'sender_type' => 'ai',
                    'content' => $result['answer'],
                    'status' => 'sent',
                    'confidence_score' => $result['confidence'],
                    'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
                    'metadata' => [
                        'context_used' => $result['context_used'],
                    ] + ($result['tool_trace'] ?? []) + (! empty($result['canned']) ? ['canned' => true] : []),
                ]);

                $inboundMessage->update(['status' => 'answered_by_ai']);
            }

            if (! empty($result['media_ids'])) {
                $this->sendMediaFiles($whatsApp, $incoming['from'], $result['media_ids']);
            }

            $business->increment('monthly_message_count');

            // Alertes à 80 % et 100 % de la limite mensuelle (une réponse toute faite ne compte pas).
            if (empty($result['canned'])) {
                $this->usage->afterReply($business);
            }
        } catch (Throwable $e) {
            Log::error('WebhookController::process a échoué', [
                'whatsapp_message_id' => $incoming['message_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoyer au client les médias sélectionnés par l'IA.
     *
     * @param  array<int, string>  $mediaIds
     */
    private function sendMediaFiles(WhatsAppService $whatsApp, string $to, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $media = BusinessMedia::find($mediaId);

            if ($media === null || empty($media->public_url)) {
                continue;
            }

            if ($media->type === 'document') {
                $extension = pathinfo((string) $media->file_path, PATHINFO_EXTENSION);
                $filename = $media->title.($extension !== '' ? ".{$extension}" : '');
                $response = $whatsApp->sendDocument($to, $media->public_url, $filename, $media->description);
            } else {
                $response = $whatsApp->sendImage($to, $media->public_url, $media->title);
            }

            Log::info('WebhookController: média envoyé au client', [
                'media_id' => $media->id,
                'type' => $media->type,
                'to' => $to,
                'whatsapp_message_id' => data_get($response, 'messages.0.id'),
            ]);
        }
    }

    /**
     * Webhook des fournisseurs de paiement (à implémenter).
     */
    public function payment(Request $request): Response
    {
        Log::info('Webhook paiement reçu', $request->all());

        return response('', 200);
    }

    private function verifyBridgeToken(Request $request): bool
    {
        return $request->header('X-Bridge-Token') === config('services.whatsapp_bridge.token');
    }

    /**
     * Réception des messages entrants depuis le bridge WhatsApp Express (Baileys).
     */
    public function handleExpress(Request $request): JsonResponse
    {
        if (! $this->verifyBridgeToken($request)) {
            return response()->json(['error' => 'Non autorisé'], 401);
        }

        $data = $request->validate([
            'business_id' => 'required|string',
            'from' => 'required|string',
            'text' => 'required|string',
            'message_id' => 'required|string',
            'customer_name' => 'nullable|string',
        ]);

        $business = Business::find($data['business_id']);
        if (! $business || ! $business->is_active) {
            return response()->json(['error' => 'Business non trouvé'], 404);
        }

        // Déduplication
        $exists = Message::where('whatsapp_message_id', $data['message_id'])
            ->where('direction', 'inbound')->exists();
        if ($exists) {
            return response()->json(['answer' => null, 'duplicate' => true]);
        }

        // Trouver ou créer la conversation
        $conversation = Conversation::firstOrCreate(
            ['business_id' => $business->id, 'customer_phone' => $data['from']],
            ['customer_name' => $data['customer_name'] ?? null, 'is_active' => true, 'last_message_at' => now()]
        );
        $conversation->update(['last_message_at' => now(), 'customer_name' => $data['customer_name'] ?? $conversation->customer_name]);

        // Sauvegarder le message entrant
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => $data['text'],
            'whatsapp_message_id' => $data['message_id'],
            'sender_type' => 'customer',
        ]);

        // Limite de réponses atteinte : message gardé, pas de réponse automatique.
        if (! $this->usage->allowsReply($business)) {
            $this->usage->recordMissedReply($business, $message);

            return response()->json([
                'answer' => null,
                'media_urls' => [],
                'media_ids' => [],
                'should_escalate' => false,
                'limit_reached' => true,
            ]);
        }

        // Traitement IA
        $result = app(AIResponseService::class)->answer($business, $data['text'], $conversation->id);

        // Sauvegarder la réponse
        if ($result['answer']) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'content' => $result['answer'],
                'sender_type' => 'ai',
                'metadata' => ['confidence' => $result['confidence']] + ($result['tool_trace'] ?? []) + (! empty($result['canned']) ? ['canned' => true] : []),
            ]);
        }

        // Gérer l'escalade
        if ($result['should_escalate']) {
            Escalation::create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'business_id' => $business->id,
                'customer_question' => $data['text'],
                'status' => 'pending',
            ]);
        }

        $business->increment('monthly_message_count');

        if ($result['answer'] && empty($result['canned'])) {
            $this->usage->afterReply($business);
        }

        // Retourner la réponse + les URLs des médias
        $mediaUrls = [];
        if (! empty($result['media_ids'])) {
            $mediaUrls = BusinessMedia::whereIn('id', $result['media_ids'])
                ->get()
                ->map(fn ($m) => ['url' => $m->public_url, 'caption' => $m->title, 'type' => $m->type])
                ->toArray();
        }

        return response()->json([
            'answer' => $result['answer'],
            'media_urls' => $mediaUrls,
            'media_ids' => $result['media_ids'] ?? [],
            'should_escalate' => $result['should_escalate'],
        ]);
    }

    /**
     * Réception des changements de statut de connexion depuis le bridge WhatsApp Express.
     */
    public function handleExpressStatus(Request $request): JsonResponse
    {
        if (! $this->verifyBridgeToken($request)) {
            return response()->json(['error' => 'Non autorisé'], 401);
        }

        $data = $request->validate([
            'business_id' => 'required|string',
            'status' => 'required|string',
            'phone' => 'nullable|string',
        ]);

        $business = Business::find($data['business_id']);
        if ($business) {
            if ($data['status'] === 'connected') {
                $business->update([
                    'whatsapp_verified' => true,
                    'whatsapp_connected_at' => now(),
                    'whatsapp_display_name' => $business->name,
                ]);
            } elseif ($data['status'] === 'disconnected') {
                $business->update(['whatsapp_verified' => false]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
