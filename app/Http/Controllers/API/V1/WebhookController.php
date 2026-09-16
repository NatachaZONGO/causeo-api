<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Services\AI\AIResponseService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly AIResponseService $ai,
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
     * Répond 200 immédiatement puis traite le message après la réponse, pour
     * éviter que Meta ne réémette le webhook (timeout ~5 s) et ne provoque des
     * réponses en double.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        $incoming = $this->whatsApp->parseIncomingMessage($payload);

        if ($incoming === null || $incoming['message_id'] === '') {
            return response('', 200);
        }

        // Déduplication : si ce message entrant a déjà été enregistré, on l'ignore.
        $alreadySeen = Message::where('whatsapp_message_id', $incoming['message_id'])
            ->where('direction', 'inbound')
            ->exists();

        if ($alreadySeen) {
            Log::info('WebhookController: message WhatsApp déjà reçu, ignoré (doublon).', [
                'whatsapp_message_id' => $incoming['message_id'],
            ]);

            return response('', 200);
        }

        $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        // Traitement lourd (IA + envois WhatsApp) exécuté après l'envoi de la réponse 200.
        dispatch(function () use ($incoming, $phoneNumberId): void {
            $this->process($incoming, $phoneNumberId);
        })->afterResponse();

        return response('', 200);
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
            $business = Business::where('whatsapp_phone_number_id', $phoneNumberId)->first();

            if ($business === null) {
                return;
            }

            $whatsApp = WhatsAppService::forBusiness($business);

            $whatsApp->markAsRead($incoming['message_id']);

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
                    ?? 'Merci pour votre message ! 😊 Je vérifie cette information avec l\'équipe et je reviens vers vous très vite.';

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
                    ],
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
                    ],
                ]);

                $inboundMessage->update(['status' => 'answered_by_ai']);
            }

            if (! empty($result['media_ids'])) {
                $this->sendMediaFiles($whatsApp, $incoming['from'], $result['media_ids']);
            }

            $business->increment('monthly_message_count');
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
            ['customer_name' => $data['customer_name'], 'is_active' => true, 'last_message_at' => now()]
        );
        $conversation->update(['last_message_at' => now(), 'customer_name' => $data['customer_name']]);

        // Sauvegarder le message entrant
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => $data['text'],
            'whatsapp_message_id' => $data['message_id'],
            'sender_type' => 'customer',
        ]);

        // Traitement IA
        $result = app(AIResponseService::class)->answer($business, $data['text'], $conversation->id);

        // Sauvegarder la réponse
        if ($result['answer']) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'content' => $result['answer'],
                'sender_type' => 'ai',
                'metadata' => ['confidence' => $result['confidence']],
            ]);
        }

        // Gérer l'escalade
        if ($result['should_escalate']) {
            Escalation::create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer_question' => $data['text'],
                'status' => 'pending',
            ]);
        }

        $business->increment('monthly_message_count');

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
