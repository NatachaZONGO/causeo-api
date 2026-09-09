<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Services\AI\AIResponseService;
use App\Services\WhatsApp\WhatsAppService;
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
     */
    public function handle(Request $request): Response
    {
        try {
            $payload = $request->all();

            $incoming = $this->whatsApp->parseIncomingMessage($payload);

            if ($incoming === null) {
                return response('', 200);
            }

            $this->whatsApp->markAsRead($incoming['message_id']);

            $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

            $business = Business::where('whatsapp_number', $phoneNumberId)->first();

            if ($business === null) {
                return response('', 200);
            }

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

            $inboundMessage = $conversation->messages()->create([
                'direction' => 'inbound',
                'sender_type' => 'customer',
                'content' => $incoming['text'],
                'status' => 'pending',
                'whatsapp_message_id' => $incoming['message_id'],
            ]);

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

                $sent = $this->whatsApp->sendMessage($incoming['from'], $waitingMessage);

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
                $sent = $this->whatsApp->sendMessage($incoming['from'], $result['answer']);

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

            $business->increment('monthly_message_count');

            return response('', 200);
        } catch (Throwable $e) {
            Log::error('WebhookController::handle a échoué', [
                'message' => $e->getMessage(),
            ]);

            return response('', 200);
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
