<?php

namespace App\Jobs;

use App\Models\Escalation;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Escalade silencieuse restée sans réponse du gérant (fallback_due_at) : un seul message
 * d'attente neutre au client (Escalation::FALLBACK_MESSAGE). Rien ne part si le gérant a
 * répondu, traité l'escalade ou mis l'assistant en pause entre-temps, ni si le client a déjà
 * reçu ce message depuis la dernière réponse du gérant. La file ne contient que l'identifiant.
 */
class SendEscalationFallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Jamais relancé : un nouvel essai risquerait d'envoyer le message deux fois. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $escalationId,
    ) {
        $this->afterCommit();
    }

    public function handle(WhatsAppServiceFactory $whatsAppFactory): void
    {
        $escalation = Escalation::with('conversation.business')->find($this->escalationId);
        $conversation = $escalation?->conversation;
        $business = $conversation?->business;

        if ($business === null || ! $this->isDue($escalation)) {
            return;
        }

        $context = ['business_id' => $business->id, 'conversation_id' => $conversation->id, 'escalation_id' => $escalation->id];

        if (ReplyToCustomer::managerRepliedSince($conversation, $escalation->created_at) || $conversation->isAiPaused()) {
            Log::info('SendEscalationFallback: le gérant a pris la main, pas de message d\'attente', $context);

            return;
        }

        // Un seul message d'attente tant que le gérant n'a pas répondu, même après plusieurs escalades.
        $lastManagerReplyAt = $conversation->messages()->where('direction', 'outbound')->where('sender_type', 'human')->max('created_at');
        $alreadySent = Escalation::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('fallback_sent_at')
            ->when($lastManagerReplyAt !== null, fn ($query) => $query->where('fallback_sent_at', '>', $lastManagerReplyAt))
            ->exists();

        if ($alreadySent) {
            $escalation->update(['fallback_due_at' => null]);
            Log::info('SendEscalationFallback: message d\'attente déjà envoyé dans cette conversation', $context);

            return;
        }

        // Marqué avant l'envoi : un nouvel essai ne l'enverra pas une seconde fois.
        $escalation->update(['fallback_sent_at' => now()]);

        $sent = $whatsAppFactory->forBusiness($business)->sendMessage($conversation->customer_phone, Escalation::FALLBACK_MESSAGE);

        $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'ai',
            'content' => Escalation::FALLBACK_MESSAGE,
            'status' => 'sent',
            'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
            'metadata' => ['escalation_fallback' => $escalation->id, 'canned' => true],
        ]);

        // Comme une réponse toute faite : compté dans les messages envoyés, pas dans les unités.
        $business->increment('monthly_message_count');

        Log::info('SendEscalationFallback: message d\'attente envoyé au client', $context + [
            'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
        ]);
    }

    /**
     * Escalade silencieuse toujours en attente, dont l'heure est venue et dont le message n'est pas parti.
     */
    private function isDue(Escalation $escalation): bool
    {
        return $escalation->silent
            && $escalation->status === Escalation::PENDING
            && $escalation->fallback_sent_at === null
            && $escalation->fallback_due_at !== null
            && $escalation->fallback_due_at->lessThanOrEqualTo(now());
    }
}
