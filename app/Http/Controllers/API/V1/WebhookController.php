<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ReplyToCustomer;
use App\Jobs\SendOwnerWhatsAppAlert;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\ManagerReply;
use App\Models\Message;
use App\Models\Notification;
use App\Services\AI\AIResponseService;
use App\Services\AI\LearningService;
use App\Services\AI\ManagerReplyService;
use App\Services\AI\VoiceMessageService;
use App\Services\Billing\UsageService;
use App\Services\Conversations\QuotedMessageService;
use App\Services\NotificationService;
use App\Services\WhatsApp\MediaMessage;
use App\Services\WhatsApp\WhatsAppAccountService;
use App\Services\WhatsApp\WhatsAppMediaService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use App\Support\EmojiStyle;
use App\Support\StatusProbe;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    /** Durée maximale (secondes) du traitement d'un message après la réponse au webhook. */
    public const BACKGROUND_TIME_LIMIT = 300;

    /**
     * Événements account_update qui coupent la liaison avec Causeo (DISABLED_UPDATE
     * n'en fait partie que pour l'état DISABLE). Référence :
     * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update/
     */
    private const DISCONNECTING_ACCOUNT_EVENTS = ['PARTNER_REMOVED', 'PARTNER_APP_UNINSTALLED', 'ACCOUNT_DELETED', 'ACCOUNT_OFFBOARDED'];

    /** Contenu d'un message vocal sans transcription (dashboard). */
    public const VOICE_PLACEHOLDER = '🎤 Message vocal';

    /** Réponse au client quand son vocal va au gérant (autre langue, incompréhensible, trop long). */
    public const VOICE_FALLBACK_MESSAGE = 'Merci pour votre message vocal ! Je le transmets tout de suite au responsable, qui vous répondra rapidement.';

    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly LearningService $learningService,
        private readonly WhatsAppAccountService $accounts,
        private readonly UsageService $usage,
        private readonly ManagerReplyService $managerReplies,
        private readonly VoiceMessageService $voiceMessages,
        private readonly WhatsAppServiceFactory $whatsAppFactory,
        private readonly NotificationService $notifications,
        private readonly WhatsAppMediaService $media,
        private readonly QuotedMessageService $quotedMessages,
    ) {}

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

            $this->probe(is_string($field) ? $field : 'inconnu', is_array($value) ? $value : []);
            $this->handleNonMessageEvent(is_string($field) ? $field : null, is_array($value) ? $value : []);

            return $this->acknowledge();
        }

        $value = data_get($payload, 'entry.0.changes.0.value');

        if (is_array($value) && $this->isNotifyNumber($value) && ! empty($value['statuses'])) {
            $this->recordNotifyStatuses((array) $value['statuses']);

            return $this->acknowledge();
        }

        // Champ « messages » sans message client (statuts d'envoi, erreurs…).
        if (is_array($value) && empty($value['messages'])) {
            $this->probe('messages', $value);
        }

        $incoming = $this->whatsApp->parseIncomingMessage($payload);

        // Message brut, pour journaliser une réponse à un contenu inconnu (statut WhatsApp…).
        $raw = data_get($payload, 'entry.0.changes.0.value.messages.0');
        $raw = is_array($raw) ? $raw : [];

        if ($incoming === null || $incoming['message_id'] === '') {
            // Type non pris en charge (« unsupported »…) : seul le journal le garde.
            if (! empty($raw['context']) || ! empty($raw['referral'])) {
                $this->quotedMessages->logIfUnknown(null, $raw);
            }

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
        dispatch(function () use ($incoming, $phoneNumberId, $raw): void {
            // Sous php-fpm, il tourne dans le processus de la requête, une fois la réponse
            // partie (fastcgi_finish_request) : la limite des requêtes ordinaires ne suffit pas.
            if (! app()->runningInConsole()) {
                ignore_user_abort(true);
                set_time_limit(self::BACKGROUND_TIME_LIMIT);
            }

            $this->process($incoming, $phoneNumberId, $raw);
        })->afterResponse();

        return $this->acknowledge();
    }

    /**
     * Accusé de réception pour Meta. Sous nginx + php-fpm, fastcgi_finish_request()
     * termine la réponse avant le traitement lancé avec afterResponse() ; le
     * Content-Length le garantit aussi sous php artisan serve (en local), où Meta
     * attendrait sinon la fermeture de la connexion.
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
     * @param  array<string, mixed>  $value
     */
    private function isNotifyNumber(array $value): bool
    {
        $notifyNumber = trim((string) config('services.causeo_notify.phone_number_id'));

        return $notifyNumber !== '' && (string) data_get($value, 'metadata.phone_number_id') === $notifyNumber;
    }

    /**
     * Statuts (sent, delivered, read, failed) des alertes envoyées aux gérants depuis le
     * numéro Causeo. Un échec est signalé aux admins dans la cloche, avec l'erreur de Meta.
     * Le numéro du gérant (recipient_id) n'est jamais journalisé.
     *
     * @param  array<int, mixed>  $statuses
     */
    private function recordNotifyStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            if (! is_array($status)) {
                continue;
            }

            $messageId = (string) ($status['id'] ?? '');
            $state = (string) ($status['status'] ?? '');
            $sent = $messageId !== '' ? Cache::get(SendOwnerWhatsAppAlert::sentMessageKey($messageId)) : null;
            $error = (array) data_get($status, 'errors.0', []);
            $details = data_get($error, 'error_data.details');

            $context = array_filter([
                'message_id' => $messageId,
                'status' => $state,
                'business_id' => $sent['business_id'] ?? null,
                'kind' => $sent['kind'] ?? null,
                'template' => $sent['template'] ?? null,
                'pricing_category' => data_get($status, 'pricing.category'),
                'error_code' => $error['code'] ?? null,
                'error_title' => isset($error['title']) ? self::maskPhoneNumbers((string) $error['title']) : null,
                'error_message' => isset($error['message']) ? self::maskPhoneNumbers((string) $error['message']) : null,
                'error_details' => $details !== null ? self::maskPhoneNumbers((string) $details) : null,
            ], fn ($item) => $item !== null && $item !== '');

            if ($state !== 'failed') {
                Log::info("WebhookController: alerte WhatsApp au gérant, statut {$state}.", $context);

                continue;
            }

            Log::error('WebhookController: alerte WhatsApp au gérant non livrée par Meta.', $context);

            // Sans message envoyé par Causeo, pas de business à rattacher : un faux statut ne remplit pas la cloche.
            if ($sent === null || ! Cache::add("whatsapp-alerts:failed:{$messageId}", true, now()->addDays(SendOwnerWhatsAppAlert::SENT_MESSAGE_TTL_DAYS))) {
                continue;
            }

            $this->notifyAdminsOfFailedAlert((string) $sent['business_id'], $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notifyAdminsOfFailedAlert(string $businessId, array $context): void
    {
        $code = isset($context['error_code']) ? " ({$context['error_code']})" : '';
        $reason = $context['error_details'] ?? $context['error_message'] ?? $context['error_title'] ?? 'raison non précisée';
        $template = $context['template'] ?? 'alerte';

        try {
            Notification::create([
                'business_id' => $businessId,
                'audience' => Notification::ADMIN,
                'type' => 'whatsapp',
                'title' => "Alerte WhatsApp non livrée{$code}",
                'body' => "Modèle {$template} : {$reason}",
                'data' => ['kind' => 'whatsapp_alert_failed'] + $context,
            ]);
        } catch (Throwable $e) {
            Log::error('WebhookController: notification admin impossible pour une alerte non livrée.', [
                'business_id' => $businessId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Masquer les numéros de téléphone d'un texte de Meta, limité à 300 caractères. */
    private static function maskPhoneNumbers(string $text): string
    {
        return mb_substr((string) preg_replace('/\+?\d[\d\s.-]{6,}\d/', '[numéro masqué]', $text), 0, 300);
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
     * Sonde TEMPORAIRE (LOG_STATUS_PROBE) : structure d'un webhook qui n'est pas un message client
     * classique, pour un business connecté (Coexistence). Rien n'est changé au traitement.
     *
     * @param  array<string, mixed>  $value
     */
    private function probe(string $field, array $value): void
    {
        if (! StatusProbe::enabled()) {
            return;
        }

        $phoneNumberId = data_get($value, 'metadata.phone_number_id');
        $businessId = empty($phoneNumberId) ? null : Business::query()
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->where('whatsapp_verified', true)
            ->value('id');

        if ($businessId !== null) {
            StatusProbe::coexistenceEvent($field, $value, $businessId);
        }
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
     * WhatsApp Business) comme des réponses humaines dans la conversation :
     * texte, vocal, photo, document, vidéo et autocollant.
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
                $type = is_array($echo) ? ($echo['type'] ?? null) : null;

                if (! in_array($type, ['text', 'audio', ...MediaMessage::TYPES], true)) {
                    continue;
                }

                // Vocal, photo, document… du gérant : gardé (consultable), sans analyse ni apprentissage.
                $isAudio = $type === 'audio';
                $media = in_array($type, MediaMessage::TYPES, true) ? MediaMessage::fromPayload($type, (array) ($echo[$type] ?? [])) : null;
                $to = $echo['to'] ?? '';
                $messageId = $echo['id'] ?? '';
                $text = match (true) {
                    $isAudio => self::VOICE_PLACEHOLDER,
                    $media !== null => MediaMessage::content($type, $media),
                    default => $echo['text']['body'] ?? '',
                };

                if ($to === '' || $messageId === '' || $text === '' || ($isAudio && empty($echo['audio']['id'])) || ($type !== 'text' && ! $isAudio && $media === null)) {
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

                $message = $conversation->messages()->create([
                    'direction' => 'outbound',
                    'sender_type' => 'human',
                    'content' => $text,
                    'status' => 'answered_by_human',
                    'whatsapp_message_id' => $messageId,
                    'metadata' => match (true) {
                        $isAudio => $this->audioMetadata([
                            'media_id' => (string) $echo['audio']['id'],
                            'mime_type' => (string) ($echo['audio']['mime_type'] ?? 'audio/ogg'),
                            'voice' => (bool) ($echo['audio']['voice'] ?? false),
                        ]),
                        $media !== null => MediaMessage::metadata($type, $media),
                        default => null,
                    },
                ]);

                if ($type === 'text') {
                    $this->managerReplies->record($business, $message, ManagerReply::SOURCE_ECHO);
                }

                // Réponse depuis son téléphone : le gérant a lu la conversation et prend la main.
                $conversation->markAsRead();
                $conversation->pauseAfterManagerMessage($business);

                // Une erreur d'apprentissage ne doit pas bloquer les échos suivants.
                try {
                    $this->learningService->answerPendingEscalation($conversation, $text, learn: $type === 'text');
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
     * Traiter effectivement un message entrant : enregistrement, transcription d'un
     * vocal, document ou vidéo transmis au gérant, sinon réponse programmée dans la
     * file (ReplyToCustomer), photos comprises.
     *
     * @param  array{type?: string, from: string, message_id: string, text: string, customer_name: ?string, media_id?: string, mime_type?: string, voice?: bool, filename?: string, caption?: string, reply_to?: string, referral?: array<string, mixed>}  $incoming
     * @param  array<string, mixed>  $raw  messages[0] du webhook, tel que reçu
     */
    private function process(array $incoming, ?string $phoneNumberId, array $raw = []): void
    {
        try {
            $business = $this->connectedBusiness($phoneNumberId);

            if ($business === null) {
                return;
            }

            $whatsApp = $this->whatsAppFactory->forBusiness($business);
            $type = $incoming['type'] ?? 'text';
            $isVoice = $type === 'audio';
            $isMedia = in_array($type, MediaMessage::TYPES, true);

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

            // Message cité ou publication d'origine : décrits à l'assistant au moment de répondre (ReplyToCustomer).
            $quote = QuotedMessageService::metadata($incoming);

            try {
                $inboundMessage = $conversation->messages()->create([
                    'direction' => 'inbound',
                    'sender_type' => 'customer',
                    'content' => match (true) {
                        $isVoice => self::VOICE_PLACEHOLDER,
                        $isMedia => MediaMessage::content($type, $incoming),
                        default => $incoming['text'],
                    },
                    // Un autocollant n'appelle ni réponse ni escalade.
                    'status' => $type === 'sticker' ? MediaMessage::IGNORED_STATUS : 'pending',
                    'whatsapp_message_id' => $incoming['message_id'],
                    'metadata' => (match (true) {
                        $isVoice => $this->audioMetadata($incoming),
                        $isMedia => MediaMessage::metadata($type, $incoming),
                        default => [],
                    } + $quote) ?: null,
                ]);
            } catch (QueryException $e) {
                // Violation de l'index unique : un autre traitement du même
                // webhook (doublon Meta) a déjà pris ce message en charge.
                Log::info('WebhookController: message entrant déjà traité, abandon.', [
                    'whatsapp_message_id' => $incoming['message_id'],
                ]);

                return;
            }

            if (! empty($raw['context']) || ! empty($raw['referral'])) {
                $this->quotedMessages->logIfUnknown($conversation, $raw, $business->id);
            }

            // Le gérant a pris la main : le message reste non lu pour lui, sans réponse, transcription ni escalade.
            if ($conversation->isAiPaused()) {
                $inboundMessage->update(['status' => Conversation::PAUSED_MESSAGE_STATUS]);

                Log::info('WebhookController: assistant en pause dans cette conversation, pas de réponse.', [
                    'business_id' => $business->id,
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            if ($type === 'sticker') {
                $whatsApp->markAsRead($incoming['message_id']);

                return;
            }

            if ($isMedia) {
                $this->recordMediaSize($business, $inboundMessage);
            }

            // Limite de réponses atteinte : le message reste enregistré (et non lu dans
            // l'app WhatsApp Business du gérant), mais l'IA n'est pas appelée.
            if (! $this->usage->allowsReply($business)) {
                $this->usage->recordMissedReply($business, $inboundMessage);

                return;
            }

            // « En train d'écrire » pendant le regroupement des messages, sauf pour ce qui va
            // au gérant sans réponse assurée (vocal pas encore compris, document, vidéo).
            $whatsApp->markAsRead($incoming['message_id'], typingIndicator: in_array($type, ['text', 'image'], true));

            if ($type === 'document' || $type === 'video') {
                $this->escalateMedia(
                    $business, $conversation, $inboundMessage, $whatsApp, $incoming['from'],
                    MediaMessage::escalationReason($type),
                    MediaMessage::escalationText($type, $incoming),
                    MediaMessage::FALLBACK_MESSAGES[$type],
                    ['media_fallback' => $type],
                    ['type' => $type, 'mime_type' => $incoming['mime_type'] ?? null],
                );

                return;
            }

            if ($isVoice) {
                $voice = $this->voiceMessages->handle($business, $incoming['media_id'], $incoming['mime_type']);

                // Le coût de la transcription reste sur le vocal lui-même (ai_usage.transcription),
                // que le bot réponde ou non.
                $inboundMessage->update([
                    'content' => $voice->transcript ?? self::VOICE_PLACEHOLDER,
                    'metadata' => $this->audioMetadata($incoming) + $voice->metadata() + $quote
                        + ($voice->usage !== null ? ['ai_usage' => ['transcription' => $voice->usage]] : []),
                ]);

                if (! $voice->isUsable()) {
                    $this->escalateMedia(
                        $business, $conversation, $inboundMessage, $whatsApp, $incoming['from'],
                        $voice->escalationReason(),
                        $voice->escalationText(),
                        self::VOICE_FALLBACK_MESSAGE,
                        ['voice_fallback' => $voice->status],
                        ['voice_status' => $voice->status, 'language' => $voice->language, 'duration_seconds' => $voice->durationSeconds],
                    );

                    return;
                }

                // Vocal en français et compris : exactement comme un message écrit.
                $whatsApp->markAsRead($incoming['message_id'], typingIndicator: true);
            }

            // Réponse par la file, une fois que le client a fini d'écrire (jamais d'attente ici).
            ReplyToCustomer::schedule($inboundMessage);
        } catch (Throwable $e) {
            Log::error('WebhookController::process a échoué', [
                'whatsapp_message_id' => $incoming['message_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vocal non compris (autre langue, incompréhensible, trop long, non transcrit),
     * document ou vidéo : escalade avec le motif (cloche et alerte WhatsApp à sa
     * création) et e-mail au gérant. Le bot ne répond au client que si le gérant l'a
     * activé (voice_fallback_reply, pour tous ces médias) ; il ne compte alors une
     * unité que dans ce cas.
     *
     * @param  array<string, mixed>  $fallbackMetadata  metadata de la réponse au client
     * @param  array<string, mixed>  $logContext
     */
    private function escalateMedia(
        Business $business,
        Conversation $conversation,
        Message $inboundMessage,
        WhatsAppService $whatsApp,
        string $to,
        string $reason,
        string $text,
        string $defaultReply,
        array $fallbackMetadata,
        array $logContext,
    ): void {
        $escalation = Escalation::create([
            'conversation_id' => $conversation->id,
            'message_id' => $inboundMessage->id,
            'business_id' => $business->id,
            'customer_question' => $text,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        $inboundMessage->update(['status' => 'escalated']);

        $replied = $business->voice_fallback_reply;

        $this->notifications->emailMediaEscalation($escalation, $replied);

        if ($replied) {
            $reply = trim((string) $business->voice_fallback_message) ?: $defaultReply;
            $sent = $whatsApp->sendMessage($to, $reply);

            $conversation->messages()->create([
                'direction' => 'outbound',
                'sender_type' => 'ai',
                'content' => $reply,
                'status' => 'sent',
                'whatsapp_message_id' => data_get($sent, 'messages.0.id'),
                'metadata' => ['escalated' => true] + $fallbackMetadata,
            ]);

            $business->increment('monthly_message_count');
            $this->usage->afterReply($business);
        }

        Log::info('WebhookController: média transmis au gérant.', [
            'business_id' => $business->id,
            'reason' => $reason,
            'replied_to_customer' => $replied,
        ] + $logContext);
    }

    /**
     * Taille du fichier (lue chez Meta sans le télécharger), pour l'affichage. Sans
     * réponse de Meta, le message reste enregistré sans taille.
     */
    private function recordMediaSize(Business $business, Message $message): void
    {
        try {
            $size = $this->media->info($business, (string) $message->metadata['media_id'])['size'];
        } catch (Throwable $e) {
            Log::info('WebhookController: taille du média inconnue.', ['message_id' => $message->id, 'exception' => $e::class]);

            return;
        }

        if ($size !== null) {
            $message->update(['metadata' => $message->metadata + ['size' => $size]]);
        }
    }

    /**
     * Ce qu'il faut garder d'un message audio pour le réécouter depuis le dashboard.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function audioMetadata(array $incoming): array
    {
        return [
            'type' => 'audio',
            'media_id' => $incoming['media_id'] ?? null,
            'mime_type' => $incoming['mime_type'] ?? null,
            'voice' => $incoming['voice'] ?? false,
        ];
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
        return hash_equals((string) config('services.whatsapp_bridge.token'), (string) $request->header('X-Bridge-Token'));
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

        if ($conversation->isAiPaused()) {
            $message->update(['status' => Conversation::PAUSED_MESSAGE_STATUS]);

            return response()->json(['answer' => null, 'media_urls' => [], 'media_ids' => [], 'should_escalate' => false, 'ai_paused' => true]);
        }

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
        if ($result['answer']) {
            $result['answer'] = EmojiStyle::apply($business, $result['answer']);
        }

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
                'reason' => $result['escalation_reason'] ?? null,
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
