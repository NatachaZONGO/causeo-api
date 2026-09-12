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
}
