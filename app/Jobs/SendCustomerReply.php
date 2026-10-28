<?php

namespace App\Jobs;

use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Billing\UsageService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Envoyer au client la réponse préparée par ReplyToCustomer, après le délai « en train
 * d'écrire ». Rien n'est envoyé si le gérant a répondu entre-temps. La réponse est
 * dans la file : celle-ci est chiffrée avec APP_KEY.
 */
class SendCustomerReply implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Jamais relancé : un nouvel essai risquerait d'envoyer la réponse deux fois. */
    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  list<string>  $messageIds  messages du client auxquels on répond
     * @param  string  $lastInboundAt  date du plus récent d'entre eux (ISO 8601)
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $mediaIds
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $messageIds,
        public readonly string $lastInboundAt,
        public readonly string $answer,
        public readonly float $confidence,
        public readonly array $metadata,
        public readonly array $mediaIds,
        public readonly bool $countsUnit,
    ) {}

    public function handle(WhatsAppServiceFactory $whatsAppFactory, UsageService $usage): void
    {
        $conversation = Conversation::with('business')->find($this->conversationId);
        $business = $conversation?->business;

        if ($business === null) {
            return;
        }

        if (ReplyToCustomer::managerRepliedSince($conversation, Carbon::parse($this->lastInboundAt))) {
            // Le gérant a répondu à ces messages ; une escalade éventuelle a été fermée par sa réponse.
            Message::query()->whereKey($this->messageIds)->where('status', 'answered_by_ai')->update(['status' => 'answered_by_human']);

            Log::info('SendCustomerReply: le gérant a répondu pendant le délai d\'écriture, réponse automatique abandonnée', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        // Le gérant a mis l'assistant en pause pendant le délai d'écriture.
        if ($conversation->isAiPaused()) {
            Message::query()->whereKey($this->messageIds)->where('status', 'answered_by_ai')->update(['status' => Conversation::PAUSED_MESSAGE_STATUS]);

            Log::info('SendCustomerReply: assistant mis en pause pendant le délai d\'écriture, réponse automatique abandonnée', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $whatsApp = $whatsAppFactory->forBusiness($business);
        $sent = $whatsApp->sendMessage($conversation->customer_phone, $this->answer);

        $reply = $conversation->messages()->create([
            'direction' => 'outbound',
            'sender_type' => 'ai',
            'content' => $this->answer,
            'status' => 'sent',
            'confidence_score' => $this->confidence,
            'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
            'metadata' => $this->metadata,
        ]);

        if ($this->mediaIds !== []) {
            $sentMedia = $this->sendMediaFiles($whatsApp, $conversation->customer_phone);

            // Le client peut répondre en citant une de ces photos : QuotedMessageService la retrouve ici.
            if ($sentMedia !== []) {
                $reply->update(['metadata' => ($reply->metadata ?? []) + ['sent_media' => $sentMedia]]);
            }
        }

        $business->increment('monthly_message_count');

        // Alertes à 80 % et 100 % de la limite mensuelle (une réponse toute faite ne compte pas).
        if ($this->countsUnit) {
            $usage->afterReply($business);
        }

        Log::info('SendCustomerReply: réponse envoyée', [
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
            'escalated' => ! empty($this->metadata['escalated']),
        ]);
    }

    /**
     * Envoyer au client les médias sélectionnés par l'IA, chaque fichier une seule fois.
     *
     * @return list<array{media_id: string, whatsapp_message_id: string}>
     */
    private function sendMediaFiles(WhatsAppService $whatsApp, string $to): array
    {
        $sent = [];
        $urls = [];

        foreach (array_unique($this->mediaIds) as $mediaId) {
            $media = BusinessMedia::find($mediaId);

            if ($media === null || empty($media->public_url) || in_array($media->public_url, $urls, true)) {
                continue;
            }

            $urls[] = $media->public_url;

            if ($media->type === 'document') {
                $extension = pathinfo((string) $media->file_path, PATHINFO_EXTENSION);
                $filename = $media->title.($extension !== '' ? ".{$extension}" : '');
                $response = $whatsApp->sendDocument($to, $media->public_url, $filename, $media->description);
            } else {
                $response = $whatsApp->sendImage($to, $media->public_url, $media->title);
            }

            Log::info('SendCustomerReply: média envoyé au client', [
                'media_id' => $media->id,
                'type' => $media->type,
                'whatsapp_message_id' => data_get($response, 'messages.0.id'),
            ]);

            if (! empty(data_get($response, 'messages.0.id'))) {
                $sent[] = ['media_id' => $media->id, 'whatsapp_message_id' => (string) data_get($response, 'messages.0.id')];
            }
        }

        return $sent;
    }
}
