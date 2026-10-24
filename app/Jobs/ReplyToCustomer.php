<?php

namespace App\Jobs;

use App\Http\Controllers\API\V1\WebhookController;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Services\AI\AIResponseService;
use App\Services\Conversations\ReplyPacing;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Répondre aux messages d'un client une fois qu'il a fini d'écrire. Chaque message
 * reçu programme ce job (voir schedule()) ; seul le dernier de la rafale répond, une
 * seule fois, à tous les messages encore sans réponse (au plus max_wait après le
 * premier). La réponse part ensuite après un délai « en train d'écrire » (SendCustomerReply).
 * La file ne contient que des identifiants.
 */
class ReplyToCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Relances quand une autre réponse de la même conversation est en préparation (voir handle()). */
    public int $tries = 5;

    public int $timeout = 150;

    /** Âge maximal d'un message sans réponse repris dans une réponse groupée. */
    private const PENDING_WINDOW_MINUTES = 15;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {}

    /**
     * Programmer la réponse au message qui vient d'arriver : chaque nouveau message relance
     * l'attente (debounce_seconds), sans dépasser max_wait_seconds après le premier.
     */
    public static function schedule(Message $inbound): void
    {
        $batch = self::pendingBatch($inbound->conversation_id);
        $delay = app(ReplyPacing::class)->groupingDelay(($batch->first() ?? $inbound)->created_at);

        self::dispatch($inbound->conversation_id, $inbound->id)->delay($delay);

        Log::info('ReplyToCustomer: réponse programmée', [
            'conversation_id' => $inbound->conversation_id,
            'message_id' => $inbound->id,
            'pending_messages' => $batch->count(),
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Messages du client encore sans réponse, du plus ancien au plus récent. Ceux d'avant
     * la dernière réponse du gérant en sont exclus : il y a répondu lui-même (ceux auxquels
     * le bot a répondu ne sont plus « pending »). Un vocal en cours de transcription n'en
     * fait pas partie : son propre job le reprendra.
     *
     * @return Collection<int, Message>
     */
    public static function pendingBatch(string $conversationId): Collection
    {
        $lastManagerReplyAt = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->where('sender_type', 'human')
            ->max('created_at');

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->where('status', 'pending')
            // Un ancien message resté « pending » (traitement interrompu) n'est pas repris.
            ->where('created_at', '>=', now()->subMinutes(self::PENDING_WINDOW_MINUTES))
            ->when($lastManagerReplyAt !== null, fn ($query) => $query->where('created_at', '>=', $lastManagerReplyAt))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Message $message) => $message->content === WebhookController::VOICE_PLACEHOLDER)
            ->values();
    }

    public function handle(AIResponseService $ai, WhatsAppServiceFactory $whatsAppFactory, ReplyPacing $pacing): void
    {
        // Une seule réponse en préparation par conversation : l'autre job réessaie un peu plus tard
        // (s'il a déjà repris ce message, il n'y aura plus rien à faire).
        $lock = Cache::lock("ai-reply:{$this->conversationId}", $this->timeout);

        if (! $lock->get()) {
            $this->release(3);

            return;
        }

        try {
            $this->reply($ai, $whatsAppFactory, $pacing);
        } catch (Throwable $e) {
            // Pas de getMessage() : une QueryException contient le SQL avec le texte du client.
            Log::error('ReplyToCustomer a échoué', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'exception' => $e::class,
                'at' => basename($e->getFile()).':'.$e->getLine(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function reply(AIResponseService $ai, WhatsAppServiceFactory $whatsAppFactory, ReplyPacing $pacing): void
    {
        $trigger = Message::find($this->messageId);

        // Déjà traité avec les messages suivants (ou par le gérant).
        if ($trigger === null || $trigger->status !== 'pending') {
            return;
        }

        $conversation = Conversation::with('business')->find($this->conversationId);
        $business = $conversation?->business;

        if ($business === null) {
            return;
        }

        $batch = self::pendingBatch($conversation->id);

        // Le gérant a répondu après ce message, pendant l'attente.
        if (! $batch->contains('id', $trigger->id)) {
            $this->closeAnsweredByManager($conversation, Message::query()->whereKey($trigger->id)->get(), 'pendant le regroupement');

            return;
        }

        $first = $batch->first();
        $newest = $batch->last();

        // Le client écrit encore : le job de son dernier message répondra à l'ensemble.
        if ($newest->id !== $trigger->id && ! $pacing->maxWaitReached($first->created_at)) {
            return;
        }

        $question = $batch->map(fn (Message $message) => trim((string) $message->content))->implode("\n");

        $startedAt = microtime(true);
        $result = $ai->answer($business, $question, $conversation->id);
        $generationSeconds = microtime(true) - $startedAt;

        if (self::managerRepliedSince($conversation, $newest->created_at)) {
            $this->closeAnsweredByManager($conversation, $batch, 'pendant la préparation de la réponse');

            return;
        }

        $escalated = (bool) $result['should_escalate'];
        $answer = $escalated
            ? ($result['answer'] ?? 'Merci pour votre message ! Je vérifie ça et je reviens vers vous très vite 😊')
            : (string) $result['answer'];

        if ($escalated) {
            Escalation::create([
                'conversation_id' => $conversation->id,
                'message_id' => $newest->id,
                'business_id' => $business->id,
                'customer_question' => $question,
                'status' => 'pending',
            ]);
        }

        Message::query()->whereKey($batch->modelKeys())->update(['status' => $escalated ? 'escalated' : 'answered_by_ai']);

        // Un vocal compte toujours comme une unité, même avec une réponse toute faite.
        $isVoice = $batch->contains(fn (Message $message) => ($message->metadata['type'] ?? null) === 'audio');
        $canned = ! empty($result['canned']);

        $metadata = ['context_used' => $result['context_used']]
            + ($escalated ? ['escalated' => true] : [])
            + ($result['tool_trace'] ?? [])
            + (! $escalated && $canned && ! $isVoice ? ['canned' => true] : [])
            + ($batch->count() > 1 ? ['grouped_messages' => $batch->count()] : []);

        // Le délai d'écriture court depuis que le bot « lit » : le temps de génération en fait partie.
        $delay = max(0, (int) round($pacing->typingSeconds($answer) - $generationSeconds));

        if ($delay > 0) {
            $this->showTyping($whatsAppFactory, $business, $newest);
        }

        SendCustomerReply::dispatch(
            $conversation->id,
            $batch->modelKeys(),
            $newest->created_at->toIso8601String(),
            $answer,
            (float) $result['confidence'],
            $metadata,
            array_values($result['media_ids'] ?? []),
            ! $canned || $isVoice,
        )->delay($delay);

        Log::info('ReplyToCustomer: réponse prête', [
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'grouped_messages' => $batch->count(),
            'escalated' => $escalated,
            'answer_length' => mb_strlen($answer),
            'generation_seconds' => round($generationSeconds, 1),
            'send_delay_seconds' => $delay,
        ]);
    }

    /**
     * Le gérant a-t-il répondu (dashboard ou application WhatsApp) depuis cette date ?
     */
    public static function managerRepliedSince(Conversation $conversation, CarbonInterface $since): bool
    {
        return $conversation->messages()
            ->where('direction', 'outbound')
            ->where('sender_type', 'human')
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function closeAnsweredByManager(Conversation $conversation, Collection $messages, string $when): void
    {
        Message::query()->whereKey($messages->modelKeys())->where('status', 'pending')->update(['status' => 'answered_by_human']);

        Log::info("ReplyToCustomer: le gérant a répondu {$when}, pas de réponse automatique", [
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'messages' => $messages->count(),
        ]);
    }

    /**
     * Relancer « en train d'écrire » : celui affiché à la réception a pu expirer (25 s au plus).
     */
    private function showTyping(WhatsAppServiceFactory $whatsAppFactory, Business $business, Message $message): void
    {
        if (empty($message->whatsapp_message_id)) {
            return;
        }

        try {
            $whatsAppFactory->forBusiness($business)->markAsRead($message->whatsapp_message_id, typingIndicator: true);
        } catch (Throwable $e) {
            Log::warning('ReplyToCustomer: indicateur « en train d\'écrire » non affiché', [
                'conversation_id' => $this->conversationId,
                'exception' => $e::class,
            ]);
        }
    }
}
