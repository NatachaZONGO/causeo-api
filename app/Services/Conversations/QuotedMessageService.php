<?php

namespace App\Services\Conversations;

use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\CustomerImageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Messages cités : le client répond à un message précis (messages[].context.id du webhook)
 * ou écrit depuis une publication ou une publicité de la boutique (messages[].referral).
 * Le message cité est retrouvé dans la conversation (réponse de l'assistant, photo qu'il a
 * envoyée, message du gérant, message du client) et décrit à l'assistant avec la question.
 *
 * Réponse à un statut WhatsApp du commerçant : Meta ne documente pas ce cas. Le contexte
 * pointe alors vers un message absent de la conversation ; l'assistant demande une capture de
 * l'article (une seule fois, puis il passe la main au gérant), et le payload brut est
 * journalisé (sans numéro) pour l'analyser.
 */
class QuotedMessageService
{
    /** Réponses de l'assistant parcourues pour retrouver une photo envoyée (metadata.sent_media). */
    private const SENT_MEDIA_LOOKBACK = 100;

    /** Champs de messages[].context connus ; un autre champ fait journaliser le payload. */
    private const KNOWN_CONTEXT_FIELDS = ['from', 'id', 'forwarded', 'frequently_forwarded', 'referred_product'];

    /** Champs de messages[].referral gardés dans la metadata du message (publicité, publication). */
    private const REFERRAL_FIELDS = ['source_type', 'source_id', 'source_url', 'headline', 'body', 'media_type', 'image_url', 'video_url', 'thumbnail_url'];

    /** Champs du payload qui contiennent un numéro ou un nom : masqués dans le journal. */
    private const PERSONAL_FIELDS = ['from', 'to', 'wa_id', 'recipient_id', 'display_phone_number', 'phone_number', 'name'];

    /** Message cité introuvable (statut WhatsApp…) : une seule question, puis escalade (AIResponseService). */
    public const KIND_UNKNOWN = 'unknown';

    /** La seule question posée quand le client répond à un statut sans dire de quel article il parle. */
    public const STATUS_CAPTURE_QUESTION = "Pouvez-vous m'envoyer une capture de l'article ?";

    /** Longueur maximale d'un message cité montré à l'assistant. */
    private const QUOTE_MAX_LENGTH = 500;

    public function __construct(
        private readonly CustomerImageService $images,
    ) {}

    /**
     * Ce qu'il faut garder d'un message entrant cité ou venu d'une publication : l'identifiant du
     * message cité et les textes de la publication (jamais le numéro de context.from).
     *
     * @param  array<string, mixed>  $incoming  voir WhatsAppService::parseIncomingMessage()
     * @return array<string, mixed>
     */
    public static function metadata(array $incoming): array
    {
        $referral = array_filter(
            array_intersect_key((array) ($incoming['referral'] ?? []), array_flip(self::REFERRAL_FIELDS)),
            fn ($value) => is_string($value) && trim($value) !== '',
        );

        return array_filter([
            'reply_to' => ! empty($incoming['reply_to']) ? (string) $incoming['reply_to'] : null,
            'referral' => $referral !== [] ? array_map(fn (string $value) => Str::limit(trim($value), 500), $referral) : null,
        ]);
    }

    /**
     * Message de la conversation dont l'identifiant WhatsApp est $whatsappMessageId, ou la
     * réponse de l'assistant qui a envoyé cette photo (avec le média), ou null.
     *
     * @return array{message: Message, media: ?BusinessMedia}|null
     */
    public function find(Conversation $conversation, string $whatsappMessageId): ?array
    {
        $message = $conversation->messages()->where('whatsapp_message_id', $whatsappMessageId)->first();

        if ($message !== null) {
            return ['message' => $message, 'media' => null];
        }

        // Photos envoyées par l'assistant : leurs identifiants sont gardés dans sa réponse (SendCustomerReply).
        $replies = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('sender_type', 'ai')
            ->latest()
            ->limit(self::SENT_MEDIA_LOOKBACK)
            ->get();

        foreach ($replies as $reply) {
            foreach ((array) ($reply->metadata['sent_media'] ?? []) as $sent) {
                if (($sent['whatsapp_message_id'] ?? null) === $whatsappMessageId) {
                    return ['message' => $reply, 'media' => BusinessMedia::find($sent['media_id'] ?? null)];
                }
            }
        }

        return null;
    }

    /**
     * Messages cités par les messages d'une rafale, décrits pour l'assistant, et les photos
     * citées à lui montrer (photo du client ou du gérant, image de la publication).
     *
     * @param  Collection<int, Message>  $batch
     * @return array{quotes: list<array{note: string, search: ?string, kind?: string}>, images: list<array<string, mixed>>}
     */
    public function forBatch(Business $business, Conversation $conversation, Collection $batch): array
    {
        $quotes = [];
        $images = [];
        $seen = [];

        foreach ($batch as $message) {
            $metadata = (array) ($message->metadata ?? []);

            if (! empty($metadata['referral'])) {
                [$quote, $image] = $this->describeReferral((array) $metadata['referral']);
                $quotes[] = $quote;
                $images[] = $image;
            }

            $replyTo = $metadata['reply_to'] ?? null;
            if (! is_string($replyTo) || isset($seen[$replyTo])) {
                continue;
            }
            $seen[$replyTo] = true;

            [$quote, $image] = $this->describeReply($business, $conversation, $replyTo);
            $quotes[] = $quote;
            $images[] = $image;
        }

        return ['quotes' => $quotes, 'images' => array_values(array_filter($images))];
    }

    /**
     * Journaliser le payload brut (sans numéro ni nom) d'un message qui cite un contenu
     * absent de la conversation (un statut WhatsApp ?), qui vient d'une publication ou d'une
     * publicité, ou dont le contexte a des champs inconnus : de quoi voir ce que Meta envoie.
     *
     * @param  array<string, mixed>  $raw  messages[0] du webhook
     */
    public function logIfUnknown(?Conversation $conversation, array $raw, ?string $businessId = null): void
    {
        $context = is_array($raw['context'] ?? null) ? $raw['context'] : [];

        $reasons = array_values(array_filter([
            ! empty($raw['referral']) ? 'referral' : null,
            ! empty($context['id']) && ($conversation === null || $this->find($conversation, (string) $context['id']) === null) ? 'message_cite_introuvable' : null,
            array_diff(array_keys($context), self::KNOWN_CONTEXT_FIELDS) !== [] ? 'champs_de_contexte_inconnus' : null,
        ]));

        if ($reasons === []) {
            return;
        }

        Log::info('QuotedMessageService: message en réponse à un contenu inconnu (statut WhatsApp, publication…), payload brut', [
            'business_id' => $businessId ?? $conversation?->business_id,
            'conversation_id' => $conversation?->id,
            'reasons' => $reasons,
            'payload' => self::redact($raw),
        ]);
    }

    /**
     * Payload sans numéro de téléphone ni nom : champs personnels masqués, numéros masqués
     * dans les textes.
     */
    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $child) {
                $redacted[$childKey] = self::redact($child, is_string($childKey) ? $childKey : $key);
            }

            return $redacted;
        }

        if (in_array($key, self::PERSONAL_FIELDS, true)) {
            return '[masqué]';
        }

        if (! is_string($value) || in_array($key, ['id', 'timestamp'], true)) {
            return $value;
        }

        return (string) preg_replace('/\+?\d[\d\s.-]{6,}\d/', '[numéro masqué]', $value);
    }

    /**
     * @return array{0: array{note: string, search: ?string}, 1: ?array<string, mixed>}
     */
    private function describeReply(Business $business, Conversation $conversation, string $replyTo): array
    {
        $found = $this->find($conversation, $replyTo);

        if ($found === null) {
            return [[
                'note' => "Le client répond à un message que tu ne vois pas (probablement un statut WhatsApp de la boutique, ou un message trop ancien). "
                    ."Si sa question ne dit pas clairement de quel article il s'agit, ne devine pas : pose une seule question, "
                    .'« '.self::STATUS_CAPTURE_QUESTION.' », puis ajoute PRECISION seul sur la dernière ligne.',
                'search' => null,
                'kind' => self::KIND_UNKNOWN,
            ], null];
        }

        $message = $found['message'];

        // Photo envoyée par l'assistant : son titre, et l'article du catalogue qu'elle montre.
        if ($found['media'] !== null) {
            $media = $found['media'];
            $product = $business->hasModule('catalog')
                ? $business->products()->whereNotNull('media_ids')->get(['id', 'name', 'media_ids'])->first(fn ($product) => in_array($media->id, (array) $product->media_ids, true))
                : null;

            return [[
                'note' => "Le client répond à la photo « {$media->title} » que tu lui as envoyée"
                    .($product !== null ? ", qui montre l'article du catalogue « {$product->name} »" : '').'.',
                'search' => $product->name ?? $media->title,
            ], null];
        }

        $text = Str::limit(trim((string) $message->content), self::QUOTE_MAX_LENGTH);
        $type = $message->metadata['type'] ?? null;

        // Photo du client ou du gérant : montrée à l'assistant, qui y reconnaît l'article.
        $image = $type === 'image' ? $this->images->prepare($business, $message) : null;
        $photo = $image !== null ? ' (cette photo est jointe au message)' : '';
        $caption = $type !== 'image' || ! empty($message->metadata['caption']) ? " : « {$text} »" : '';

        $note = match (true) {
            $message->direction === 'inbound' => ($type === 'image' ? 'Le client cite une photo qu\'il a envoyée' : 'Le client cite un de ses messages précédents').$caption.$photo.'.',
            $message->sender_type === 'ai' => "Le client répond à ce message que tu lui as envoyé : « {$text} ».",
            default => ($type === 'image' ? 'Le client répond à une photo envoyée par le gérant' : 'Le client répond à ce message du gérant').$caption.$photo.'.',
        };

        return [['note' => $note, 'search' => $type === 'image' && $caption === '' ? null : Str::limit($text, 200)], $image];
    }

    /**
     * @param  array<string, string>  $referral
     * @return array{0: array{note: string, search: ?string}, 1: ?array<string, mixed>}
     */
    private function describeReferral(array $referral): array
    {
        $image = ! empty($referral['image_url']) ? $this->images->fromUrl($referral['image_url']) : null;
        $texts = array_filter([
            ! empty($referral['headline']) ? "titre « {$referral['headline']} »" : null,
            ! empty($referral['body']) ? "texte « {$referral['body']} »" : null,
        ]);

        $note = 'Le client écrit depuis une publication ou une publicité de la boutique'
            .($texts !== [] ? ' ('.implode(', ', $texts).')' : '')
            .($image !== null ? ". L'image de cette publication est jointe au message" : '')
            .'. Si elle ne suffit pas à savoir quel article l\'intéresse, demande-le-lui poliment.';

        $search = trim(($referral['headline'] ?? '').' '.($referral['body'] ?? ''));

        return [['note' => $note, 'search' => $search !== '' ? Str::limit($search, 200) : null], $image];
    }
}
