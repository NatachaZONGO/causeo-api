<?php

namespace App\Services\AI;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Embedding\EmbeddingService;
use App\Services\AppointmentService;
use App\Services\Catalog\CatalogService;
use App\Services\OrderService;
use App\Services\WhatsApp\MediaMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AIResponseService
{
    private string $model;

    private const SIMILARITY_THRESHOLD = 0.5;

    /** Nombre maximal de messages de la session envoyés à Claude, en plus du message courant. */
    private const HISTORY_MAX_MESSAGES = 20;

    /** Nombre maximal d'allers-retours tool_use / tool_result par réponse. */
    private const MAX_TOOL_ROUNDS = 4;

    /** Tokens consommés pendant une réponse, additionnés sur tous les appels à Claude. */
    private const EMPTY_USAGE = [
        'calls' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
    ];

    /** Longueur maximale d'une réponse (tokens) : 1 à 3 phrases, une courte liste au plus. */
    private const MAX_TOKENS = 400;

    /** Réglage « très courte » du gérant. */
    private const MAX_TOKENS_VERY_SHORT = 250;

    /** En plus quand des outils sont proposés : un appel à create_order porte toute la commande. */
    private const MAX_TOKENS_TOOLS_EXTRA = 400;

    /** Ancien plafond, gardé pour l'évaluation (withLegacyStyle). */
    private const LEGACY_MAX_TOKENS = 1024;

    /** Exemples de réponses du gérant ajoutés au prompt, au plus. */
    private const OWNER_EXAMPLES = 3;

    /** Ancien prompt de style (sans profil ni exemples), pour comparer avant / après. */
    private bool $legacyStyle = false;

    /** @var array<string, string> embeddings déjà calculés pendant cette réponse (texte => vecteur pgvector) */
    private array $vectors = [];

    private readonly ManagerReplyService $managerReplies;

    private readonly CatalogService $catalog;

    /** Sections du prompt système : personnalité, langue, style, remerciements. */
    private const STYLE_SECTIONS = [
        <<<'TEXT'
- Tu écris comme le gérant écrirait lui-même à son client : simple, direct, chaleureux, jamais mécanique ni commercial.
- Adapte la longueur et le registre au message du client : à un message court ou familier, réponds court et simplement ; à un message long ou formel, reste posé et un peu plus complet. Le tutoiement ou le vouvoiement, lui, ne change jamais (voir les règles absolues).
- Pas d'emoji, sauf si les réglages du gérant (plus bas) en prévoient.
TEXT,
        <<<'TEXT'
- Réponds dans la langue du client (en français s'il écrit en français), de façon fluide et humaine.
TEXT,
        <<<'TEXT'
- 1 à 3 phrases par défaut. Pas de phrase d'introduction ni de conclusion.
- Une liste seulement si le client demande plusieurs éléments (plusieurs produits, plusieurs prix…) : un élément par ligne, sans titre.
- Une seule question à la fois, et seulement si elle fait avancer la demande. Pas de question de relance systématique à la fin.
- Aucun titre et aucune mise en forme Markdown (ni *, ni **, ni #, ni _) : du texte simple, comme un vrai message WhatsApp.
- Si le client pose plusieurs questions, réponds à chacune brièvement, dans l'ordre.
- Expressions interdites, qui sonnent comme un robot : « N'hésitez pas », « Je serais ravi(e) », « Je suis ravi(e) », « Ravi(e) de », « Absolument ! », « Bien sûr ! » ou « Certainement ! » en début de phrase, « En tant qu'assistant », « Je comprends votre demande », « Je comprends tout à fait », « Excellente question », « Merci pour votre message », « J'espère que cela vous aide », « Je reste à votre disposition », « Cordialement ».
TEXT,
        <<<'TEXT'
- Si le client dit « Merci », « Ok merci », « C'est noté »… : réponds brièvement (« Avec plaisir ! »), sans relancer. N'ajoute pas le marqueur JE_NE_SAIS_PAS dans ce cas.
TEXT,
    ];

    /** Sections d'avant le profil de style, pour l'évaluation avant / après. */
    private const LEGACY_STYLE_SECTIONS = [
        <<<'TEXT'
- Chaleureux, accueillant, poli. Tu parles comme un bon agent d'accueil burkinabè : naturel, respectueux, jamais froid ni mécanique.
- Ton amical mais professionnel. Tu peux utiliser 1 à 2 émojis maximum par message, jamais plus, et seulement quand c'est naturel.
- Tu adaptes ton niveau de langue à celui du client : s'il est très informel, tu te détends un peu ; s'il est formel, tu restes courtois et posé.
TEXT,
        <<<'TEXT'
- Réponds TOUJOURS en français, de façon fluide et humaine.
TEXT,
        <<<'TEXT'
- Sois concis : 3 à 4 courts paragraphes maximum. Les clients WhatsApp veulent des réponses rapides et claires.
- Mets en forme pour WhatsApp : *gras* pour les titres et les éléments importants (prix, noms de produits, points clés), des sauts de ligne pour aérer. Pour une liste, va à la ligne pour chaque élément.
- Si le client pose plusieurs questions à la fois, réponds à toutes, de manière organisée (une partie par question si besoin).
- Termine souvent par une question de relance neutre, qui ne propose aucun service : « Avez-vous une préférence particulière ? », « Puis-je vous aider pour autre chose ? »
TEXT,
        <<<'TEXT'
- Si le client dit « Merci », « Ok merci », « C'est noté »… : réponds poliment (« Avec plaisir ! »), et propose ton aide pour autre chose. N'ajoute pas le marqueur JE_NE_SAIS_PAS dans ce cas.
TEXT,
    ];

    /** Consignes quand l'outil create_appointment n'est pas proposé (module inactif ou hors formule). */
    private const NO_APPOINTMENT_INSTRUCTIONS = <<<'TEXT'
# Demandes de rendez-vous ou de réservation
Tu ne peux pas enregistrer de rendez-vous ni de réservation toi-même. Si le client veut prendre rendez-vous, réserver ou bloquer un créneau :
- Ne dis jamais que le rendez-vous ou la réservation est noté, enregistré, pris, réservé ou confirmé, et ne fais pas de récapitulatif à confirmer.
- Dis-lui simplement que tu transmets sa demande au gérant (avec la prestation, la date et l'heure souhaitées s'il les a données), qui reviendra vers lui pour la suite. C'est la seule exception à la règle qui interdit de parler de transmission.
- Puis ajoute le marqueur JE_NE_SAIS_PAS, seul sur la dernière ligne.
TEXT;

    /** Ligne technique ajoutée par Claude quand une photo est la capture d'un paiement. */
    private const PAYMENT_PROOF_MARKER = 'PREUVE_PAIEMENT';

    /** Réponse au client pour une preuve de paiement : jamais de confirmation du paiement. */
    private const PAYMENT_PROOF_REPLY = "Merci, j'ai bien reçu votre capture 🙏 Le responsable va vérifier le paiement et revient vers vous très vite.";

    private const PAYMENT_PROOF_REPLY_TU = "Merci, j'ai bien reçu ta capture 🙏 Le responsable va vérifier le paiement et revient vers toi très vite.";

    /** Recherche documentaire pour une photo sans légende : les pages de catalogue et de prix. */
    private const PHOTO_CONTEXT_QUERY = 'catalogue des articles, produits, modèles, prix et disponibilité';

    private const PHOTO_CONTEXT_LIMIT = 8;

    /** Médias du business listés comme catalogue quand le client envoie une photo. */
    private const PHOTO_CATALOG_LIMIT = 30;

    /** Consignes quand le catalogue est disponible (outil search_catalog). */
    private const CATALOG_INSTRUCTIONS = <<<'TEXT'
# Catalogue et stock
Le catalogue de la boutique se consulte avec l'outil search_catalog. Il fait foi pour les articles, les prix, les variantes (tailles, couleurs…) et la disponibilité : il prime toujours sur les documents, les réponses apprises et l'historique. Les documents ne servent qu'aux informations générales (horaires, adresse, livraison, paiement, conditions, politique de retour…).
1. Avec la question, tu reçois le résultat d'une première recherche dans le catalogue. Dès que le client parle d'un article, d'un prix, d'une taille, d'une couleur ou d'une disponibilité, appuie-toi sur ce résultat, ou appelle search_catalog (avec les filtres utiles) s'il ne suffit pas, avant de répondre.
2. N'annonce que ce que renvoie le catalogue : le nom, le prix et la disponibilité (« disponible », « dernières pièces », « en rupture »). Ne donne jamais un nombre de pièces en stock.
3. Un article, un prix ou une variante qui figure dans un document mais pas au catalogue ne se vend pas : ne le propose pas et ne donne pas ce prix. Si un document et le catalogue donnent des prix différents, seul le prix du catalogue compte.
4. Si l'article demandé n'est pas au catalogue, dis-le simplement (« Je ne trouve pas cet article dans notre catalogue ») et propose les articles proches que renvoie le catalogue (même catégorie, même usage). S'il n'y en a aucun, ou si la demande reste floue, applique la règle d'escalade.
5. En rupture : dis-le simplement et propose une autre variante disponible du même article, ou un article proche renvoyé par le catalogue.
6. Ne choisis JAMAIS une taille, une couleur, une quantité ou une autre option à la place du client, et ne fais pas comme s'il l'avait donnée (pas de « en taille S, c'est bien ça ? » s'il n'a parlé d'aucune taille). Demande-la, en une seule question, avec les options disponibles du catalogue : « Quelle taille souhaitez-vous : S, M, L ou XL ? ».
TEXT;

    /** Résultats du catalogue envoyés avec la question, au plus. */
    private const CATALOG_PREFETCH_LIMIT = 5;

    /** Outil de recherche dans le catalogue (module « catalog »). */
    private const SEARCH_CATALOG_TOOL = [
        'name' => 'search_catalog',
        'description' => 'Cherche des articles dans le catalogue de la boutique. Renvoie les articles correspondants avec leurs variantes (options comme la taille ou la couleur), leur prix en FCFA et leur disponibilité (disponible, dernières pièces, en rupture), et le variant_id à utiliser pour une commande.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Ce que cherche le client, en quelques mots (par exemple « robe wax rouge », « chemise lin »).'],
                'size' => ['type' => 'string', 'description' => 'Taille ou pointure demandée, facultatif.'],
                'color' => ['type' => 'string', 'description' => 'Couleur demandée, facultatif.'],
                'category' => ['type' => 'string', 'description' => 'Catégorie, facultatif.'],
                'max_price' => ['type' => 'number', 'description' => 'Prix maximal en FCFA, facultatif.'],
            ],
            'required' => ['query'],
        ],
    ];

    /** Message d'attente envoyé quand une réponse est bloquée puis escaladée. */
    private const WAITING_MESSAGE = 'Je vérifie ça et je reviens vers vous très vite 😊';

    /** Libellés des modes de remise, tels que présentés au client. */
    private const FULFILLMENT_LABELS = [
        'delivery' => 'livraison à domicile (delivery)',
        'pickup' => 'retrait en boutique (pickup)',
        'shipping' => 'expédition vers une autre ville (shipping)',
    ];

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly OrderService $orderService,
        private readonly AppointmentService $appointmentService,
        ?ManagerReplyService $managerReplies = null,
        ?CatalogService $catalog = null,
    ) {
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $this->managerReplies = $managerReplies ?? app(ManagerReplyService::class);
        $this->catalog = $catalog ?? app(CatalogService::class);
    }

    /**
     * Copie du service avec l'ancien prompt de style : réponses longues, mise en forme,
     * ni profil ni exemples du gérant. Sert seulement à l'évaluation (causeo:style-eval).
     */
    public function withLegacyStyle(): static
    {
        $service = clone $this;
        $service->legacyStyle = true;

        return $service;
    }

    /**
     * Générer une réponse à la question d'un client pour une entreprise donnée.
     *
     * Photos du client : blocs image déjà préparés (CustomerImageService), montrés à Claude
     * avec la question ; $unreadableImages photos n'ont pas pu l'être. Messages cités : ceux
     * auxquels le client répond (QuotedMessageService), décrits avant sa question.
     *
     * @param  list<array<string, mixed>>  $images
     * @param  list<array{note: string, search?: ?string}>  $quotes
     * @return array{answer: ?string, confidence: float, should_escalate: bool, context_used: array<int, mixed>, media_ids: array<int, string>, payment_proof?: bool}
     */
    public function answer(Business $business, string $question, ?string $conversationId = null, array $images = [], int $unreadableImages = 0, array $quotes = []): array
    {
        $conversation = $conversationId !== null ? Conversation::find($conversationId) : null;
        $withPhotos = $images !== [] || $unreadableImages > 0;

        // Seuls les messages de la session en cours comptent : après une longue pause,
        // un ancien sujet ou un récapitulatif resté sans réponse est abandonné.
        $session = $this->sessionRows($conversationId);
        $lastBotMessage = $session->last(fn (object $row) => $row->direction === 'outbound');

        // Après une question du bot (« J'enregistre votre demande ? »), « Ok merci » ou
        // « D'accord merci » répondent à cette question : seule l'IA peut appeler l'outil.
        $awaitsAnswer = $lastBotMessage !== null && str_contains((string) $lastBotMessage->content, '?');

        // Une réponse à un message cité dépend de ce message : pas de réponse toute faite.
        $simple = $withPhotos || $quotes !== [] ? null : $this->handleSimpleMessage($business, $question, $lastBotMessage !== null, $awaitsAnswer);
        if ($simple !== null) {
            Log::info('AIResponseService: réponse toute faite, sans appel à Claude', [
                'business_id' => $business->id,
                'conversation_id' => $conversationId,
                'awaits_answer' => $awaitsAnswer,
            ]);

            return $simple;
        }

        $context = collect();
        $trace = ['orders' => [], 'appointments' => [], 'tool_errors' => [], 'blocked_claim' => false, 'usage' => self::EMPTY_USAGE];

        try {
            // Avec une photo, la légende guide la recherche ; sans légende, on cherche le catalogue
            // (prix, articles), et tous les médias du business servent de catalogue illustré.
            // Le message cité précise la demande (« Combien ? » en réponse à la photo d'une robe).
            $searchText = trim(($withPhotos ? $this->withoutPhotoPlaceholders($question) : $question)
                ."\n".implode("\n", array_filter(array_column($quotes, 'search'))));
            $context = $withPhotos
                ? $this->findRelevantContext($business, $searchText !== '' ? $searchText : self::PHOTO_CONTEXT_QUERY, self::PHOTO_CONTEXT_LIMIT)
                : $this->findRelevantContext($business, $searchText);
            $hasContext = $context->isNotEmpty();

            $media = $withPhotos ? $this->catalogMedia($business) : $this->findRelevantMedia($business, $searchText);

            $messages = $this->buildConversationHistory($session, $conversation, $question);

            // Le prompt système est envoyé en deux blocs : d'abord la partie stable pour une
            // entreprise (règles, informations, consignes de commande et de rendez-vous), mise en
            // cache avec les outils qui la précèdent ; puis la partie qui change d'une question à
            // l'autre (heure, demandes en cours, médias…), hors cache.
            $stablePrompt = $this->buildSystemPrompt($business);

            // Commandes et rendez-vous ne sont possibles que dans une vraie conversation
            // client, avec le module correspondant actif (et au moins un mode de remise
            // proposé pour les commandes).
            // Le catalogue (module « catalog » et au moins un article actif) se consulte aussi hors conversation.
            $catalogAvailable = $this->catalog->isAvailable($business);

            $tools = [];
            if ($conversation !== null && $business->hasModule('orders') && $business->enabledFulfillmentTypes() !== []) {
                $stablePrompt .= "\n\n".$this->orderInstructions($business, $catalogAvailable);
                $tools[] = $this->createOrderTool($business, $catalogAvailable);
            }
            if ($catalogAvailable) {
                $stablePrompt .= "\n\n".self::CATALOG_INSTRUCTIONS;
                $tools[] = self::SEARCH_CATALOG_TOOL;
            }
            $appointmentsAvailable = $conversation !== null && $business->hasModule('appointments');
            if ($appointmentsAvailable) {
                $stablePrompt .= "\n\n".$this->appointmentInstructions($business);
                $tools[] = $this->createAppointmentTool();
            } else {
                // Sans l'outil, une demande de rendez-vous va au gérant : jamais de « c'est noté ».
                $stablePrompt .= "\n\n".self::NO_APPOINTMENT_INSTRUCTIONS;
            }

            Log::info('AIResponseService: outils proposés', [
                'business_id' => $business->id,
                'conversation_id' => $conversationId,
                'tools' => array_column($tools, 'name'),
                // Activé par le gérant mais absent de la formule : l'outil n'est pas proposé.
                'appointments_module' => $business->moduleEnabled('appointments')
                    ? ($business->hasModule('appointments') ? 'active' : 'hors_formule')
                    : 'desactive',
            ]);

            $systemPrompt = $this->timeOfDayBlock($business);

            $pendingSummary = $this->pendingRequestsSummary($conversation);
            if ($pendingSummary !== null) {
                $systemPrompt .= "\n\n".$pendingSummary;
            }

            if ($messages !== []) {
                $systemPrompt .= "\n\n# Contexte de la conversation\n"
                    ."Note : cette conversation est déjà en cours, le client a déjà été accueilli. "
                    .'Réponds directement à sa question sans resaluer.';
            }

            if ($media->isNotEmpty()) {
                $systemPrompt .= "\n\n# Médias disponibles à envoyer\n"
                    ."Certains médias (images, documents, catalogues) peuvent être envoyés au client. "
                    ."S'ils sont pertinents pour sa question (il demande à voir le menu, le catalogue, une photo, un document...), "
                    ."mentionne-les naturellement dans ta réponse ET ajoute, tout à la fin de ta réponse, seul sur la dernière ligne, "
                    ."la ligne spéciale : MEDIA:id1,id2 (les identifiants exacts des médias à envoyer, séparés par des virgules). "
                    ."N'invente jamais d'identifiant et n'ajoute cette ligne que si un média listé est réellement utile. "
                    .'Cette ligne est technique : elle ne doit jamais apparaître dans une phrase adressée au client.';
            }

            // Le catalogue fait foi : son résultat précède les documents, qui ne servent alors
            // qu'aux informations générales (une ancienne liste de prix ne doit pas l'emporter).
            $catalogBlock = $catalogAvailable && $searchText !== '' ? $this->catalogBlock($business, $searchText, $conversationId) : '';
            $quotesBlock = $quotes !== [] ? implode("\n", array_column($quotes, 'note'))."\n\n" : '';

            if ($quotes !== []) {
                $systemPrompt .= "\n\n# Message cité\n"
                    ."Le client répond à un message précis, décrit juste avant sa question. Réponds en en tenant compte : "
                    ."« Combien ? » en réponse à la photo d'un article porte sur cet article.";
            }

            if ($hasContext) {
                $contextText = $context
                    ->map(fn ($item) => '- '.$item->content)
                    ->implode("\n");

                $contextLabel = $catalogAvailable
                    ? 'Documents (informations générales ; pour les articles, les prix, les variantes et la disponibilité, seul le catalogue fait foi) :'
                    : 'Contexte :';

                $userMessage = "{$catalogBlock}{$contextLabel}\n{$contextText}\n\n{$quotesBlock}Question du client : {$question}";
            } else {
                $systemPrompt .= "\n\n# Aucune information trouvée dans la base documentaire\n"
                    ."Aucune information spécifique n'a été trouvée dans la base documentaire. "
                    ."Si le client fait de la conversation simple (salutation, remerciement, question générale sur l'entreprise), "
                    ."réponds naturellement et chaleureusement. "
                    ."Les informations déjà données dans l'historique de cette conversation (par exemple un récapitulatif de commande) restent valables. "
                    ."Si le client pose une question technique ou spécifique sur un produit / service / prix / horaire "
                    .($catalogAvailable ? "que l'outil search_catalog ne trouve pas non plus (appelle-le d'abord pour tout article ou prix), " : '')
                    ."que tu ne connais pas, ne confirme rien et n'infirme rien : réponds par un message d'attente court, "
                    ."par exemple « Je vérifie ça et je reviens vers vous très vite », sans mentionner d'équipe, "
                    ."de responsable ni de transmission, puis ajoute JE_NE_SAIS_PAS seul sur la dernière ligne.";

                $userMessage = ($catalogBlock.$quotesBlock) !== '' ? "{$catalogBlock}{$quotesBlock}Question du client : {$question}" : $question;
            }

            // Photos des articles du catalogue : la photo du client peut y être reconnue.
            $productsByMedia = $withPhotos && $catalogAvailable ? $this->catalogProductsByMedia($business) : [];

            if ($media->isNotEmpty()) {
                $mediaList = $withPhotos
                    ? $media->map(fn ($m) => '- '.$this->catalogLine($m, $productsByMedia))->implode("\n")
                    : $media->map(fn ($m) => "{$m->title} (id: {$m->id}, type: {$m->type})")->implode(', ');
                $userMessage .= $withPhotos
                    ? "\n\nMédias du business (catalogue illustré), aussi disponibles à envoyer au client :\n{$mediaList}"
                    : "\n\nMédias disponibles à envoyer au client : {$mediaList}";
            }

            if ($withPhotos) {
                $systemPrompt .= "\n\n".$this->photoInstructions(count($images), $unreadableImages, in_array('create_order', array_column($tools, 'name'), true));
            }

            $examples = $this->legacyStyle ? collect() : $this->findOwnerExamples($business, $question);
            if ($examples->isNotEmpty()) {
                $systemPrompt .= "\n\n".$this->ownerExamplesBlock($examples);
            }

            // Dernière consigne lue : consignes du gérant, exemples ou documents en « tu » ne la changent pas.
            $systemPrompt .= "\n\n# Rappel\n".($this->addressOf($business) === 'tu'
                ? 'Tutoie le client dans ta réponse.'
                : 'Vouvoie le client dans ta réponse (« vous », « votre »), jamais « tu », « te », « ton » ou « ta ».');

            if ($messages !== [] && end($messages)['role'] === 'user') {
                $messages[count($messages) - 1]['content'] .= "\n\n".$userMessage;
            } else {
                $messages[] = ['role' => 'user', 'content' => $userMessage];
            }

            // Les photos précèdent le texte du dernier message du client.
            if ($images !== []) {
                $last = count($messages) - 1;
                $messages[$last]['content'] = [...$images, ['type' => 'text', 'text' => $messages[$last]['content']]];
            }

            // Le cache couvre les outils et le premier bloc (rendus dans cet ordre). En dessous
            // de la taille minimale du modèle (4 096 tokens pour Haiku 4.5), l'API ignore
            // simplement le marqueur ; usage.cache_* reste alors à zéro.
            $request = [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens($business, $tools !== []),
                'system' => [
                    ['type' => 'text', 'text' => $stablePrompt, 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => $systemPrompt],
                ],
                'messages' => $messages,
            ];
            if ($tools !== []) {
                $request['tools'] = $tools;
            }

            $payload = $this->converse($request, $business, $conversation, $trace);
            $text = $this->extractText($payload);

            // Capture d'un paiement : jamais confirmé par le bot, le gérant vérifie.
            if ($withPhotos && str_contains($text, self::PAYMENT_PROOF_MARKER)) {
                Log::info('AIResponseService: preuve de paiement reconnue sur une photo', [
                    'business_id' => $business->id,
                    'conversation_id' => $conversationId,
                ]);

                return [
                    'answer' => $this->addressOf($business) === 'tu' ? self::PAYMENT_PROOF_REPLY_TU : self::PAYMENT_PROOF_REPLY,
                    'confidence' => 0.5,
                    'should_escalate' => true,
                    'payment_proof' => true,
                    'context_used' => $context->pluck('id')->all(),
                    'media_ids' => [],
                    'tool_trace' => $this->traceForMetadata($trace),
                ];
            }

            if (($payload['stop_reason'] ?? null) === 'max_tokens') {
                Log::warning('AIResponseService: réponse coupée par max_tokens.', [
                    'business_id' => $business->id,
                    'max_tokens' => $request['max_tokens'],
                ]);
            }

            // Une réponse qui annonce une commande enregistrée (ou cite une référence)
            // sans appel réussi à create_order n'est jamais envoyée : un seul nouvel
            // essai avec une consigne de correction, puis escalade.
            $violation = $this->unverifiedOrderClaim($text, $trace, $conversation, $appointmentsAvailable);

            if ($violation !== null) {
                Log::warning('AIResponseService: annonce de commande ou de rendez-vous non vérifiée bloquée', [
                    'conversation_id' => $conversationId,
                    'reason' => $violation,
                    'text_length' => mb_strlen($text),
                ]);

                $request['messages'][] = ['role' => 'assistant', 'content' => $payload['content']];
                $request['messages'][] = ['role' => 'user', 'content' => $this->orderClaimCorrection($violation, $appointmentsAvailable)];

                $payload = $this->converse($request, $business, $conversation, $trace);
                $text = $this->extractText($payload);
                $violation = $this->unverifiedOrderClaim($text, $trace, $conversation, $appointmentsAvailable);

                if ($violation !== null) {
                    Log::warning('AIResponseService: annonce de commande ou de rendez-vous non vérifiée bloquée deux fois, escalade', [
                        'conversation_id' => $conversationId,
                        'reason' => $violation,
                        'text_length' => mb_strlen($text),
                    ]);

                    $trace['blocked_claim'] = true;

                    return [
                        'answer' => self::WAITING_MESSAGE,
                        'confidence' => 0.2,
                        'should_escalate' => true,
                        'context_used' => $context->pluck('id')->all(),
                        'media_ids' => [],
                        'tool_trace' => $this->traceForMetadata($trace),
                    ];
                }
            }

            // Un tutoiement alors que le business vouvoie : un seul nouvel essai. S'il tutoie encore
            // (ou que la réécriture pose un autre problème), la première réponse part quand même.
            if ($this->addressOf($business) === 'vous' && $this->usesTu($text)) {
                Log::warning('AIResponseService: tutoiement alors que le business vouvoie, réponse réécrite', [
                    'business_id' => $business->id,
                    'conversation_id' => $conversationId,
                ]);

                $request['messages'][] = ['role' => 'assistant', 'content' => $payload['content']];
                $request['messages'][] = ['role' => 'user', 'content' => '[Message système, pas du client : ne le mentionne pas dans ta réponse] '
                    .'Ta réponse précédente tutoie le client. Réécris exactement la même réponse en le vouvoyant (« vous », « votre », « vos »).'];

                $retry = $this->converse($request, $business, $conversation, $trace);
                $rewritten = $this->extractText($retry);

                if ($rewritten !== '' && ! $this->usesTu($rewritten) && $this->unverifiedOrderClaim($rewritten, $trace, $conversation, $appointmentsAvailable) === null) {
                    $text = $rewritten;
                } else {
                    Log::warning('AIResponseService: réécriture au vouvoiement échouée, première réponse gardée', [
                        'business_id' => $business->id,
                        'conversation_id' => $conversationId,
                    ]);
                }
            }

            // Extrait puis retire la ligne technique MEDIA:id1,id2 de la réponse.
            $mediaIds = [];
            if ($media->isNotEmpty() && preg_match('/^\s*MEDIA\s*:\s*(.+)$/mi', $text, $matches)) {
                $requested = array_filter(array_map('trim', explode(',', $matches[1])));
                $validIds = $media->pluck('id')->all();
                $mediaIds = $this->distinctMedia($business, $media, array_values(array_unique(array_intersect($requested, $validIds))));

                $text = trim(preg_replace('/^\s*MEDIA\s*:.*$/mi', '', $text) ?? $text);
            }

            if ($text === '') {
                return [
                    'answer' => null,
                    'confidence' => 0,
                    'should_escalate' => true,
                    'context_used' => $context->pluck('id')->all(),
                    'media_ids' => [],
                    'tool_trace' => $this->traceForMetadata($trace),
                ];
            }

            if (str_contains($text, 'JE_NE_SAIS_PAS')) {
                $visible = trim(str_replace('JE_NE_SAIS_PAS', '', $text));

                return [
                    'answer' => $visible !== '' ? $visible : null,
                    'confidence' => 0.2,
                    'should_escalate' => true,
                    'context_used' => $context->pluck('id')->all(),
                    'media_ids' => [],
                    'tool_trace' => $this->traceForMetadata($trace),
                ];
            }

            return [
                'answer' => $text,
                'confidence' => $hasContext ? $this->averageSimilarity($context) : 0.3,
                'should_escalate' => false,
                'context_used' => $context->pluck('id')->all(),
                'media_ids' => $mediaIds,
                'tool_trace' => $this->traceForMetadata($trace),
            ];
        } catch (\Throwable $e) {
            Log::error('AIResponseService::answer a échoué', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'answer' => null,
                'confidence' => 0,
                'should_escalate' => true,
                'context_used' => $context->pluck('id')->all(),
                'media_ids' => [],
                'tool_trace' => $this->traceForMetadata($trace),
            ];
        }
    }

    /**
     * Appeler Claude puis exécuter les appels d'outils jusqu'à la réponse finale.
     *
     * @param  array<string, mixed>  $request  complété avec les échanges tool_use / tool_result
     * @param  array{orders: array<int, array{id: string, reference: string}>, appointments: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool, usage: array<string, int>}  $trace
     * @return array<string, mixed>
     */
    private function converse(array &$request, Business $business, ?Conversation $conversation, array &$trace): array
    {
        $payload = $this->callClaude($request);
        $this->addUsage($trace, $payload);

        for ($round = 0; ($payload['stop_reason'] ?? null) === 'tool_use' && $round < self::MAX_TOOL_ROUNDS; $round++) {
            $request['messages'][] = ['role' => 'assistant', 'content' => $payload['content']];
            $request['messages'][] = [
                'role' => 'user',
                'content' => $this->runTools($payload['content'], $business, $conversation, $trace),
            ];

            $payload = $this->callClaude($request);
            $this->addUsage($trace, $payload);
        }

        return $payload;
    }

    /**
     * Additionner les tokens d'un appel à Claude (outils et nouvel essai compris).
     *
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $payload
     */
    private function addUsage(array &$trace, array $payload): void
    {
        $trace['usage']['calls']++;

        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            $trace['usage'][$key] += (int) data_get($payload, "usage.{$key}", 0);
        }
    }

    /**
     * Vérifier qu'une réponse n'annonce pas une commande ou une demande de
     * rendez-vous qui n'existe pas, ni un rendez-vous réservé que l'entreprise
     * n'a pas confirmé. Renvoie la raison du blocage, ou null si la réponse peut
     * être envoyée.
     *
     * @param  array{orders: array<int, array{id: string, reference: string}>, appointments: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool, usage: array<string, int>}  $trace
     */
    private function unverifiedOrderClaim(string $text, array $trace, ?Conversation $conversation, bool $appointmentsAvailable = true): ?string
    {
        $cited = $this->citedOrderReferences($text);
        $claimsOrder = $this->claimsOrderRecorded($text);
        $claimsRequest = $this->claimsAppointmentRequestRecorded($text);
        $claimsBooked = $this->claimsAppointmentBooked($text);

        if ($cited === [] && ! $claimsOrder && ! $claimsRequest && ! $claimsBooked) {
            return null;
        }

        // Sans l'outil, aucune demande ne peut avoir été enregistrée pendant cet échange.
        if (! $appointmentsAvailable && ($claimsRequest || $claimsBooked) && ! $this->hasConfirmedAppointment($conversation)) {
            return 'rendez-vous annoncé comme noté ou réservé alors que l\'assistant ne peut pas en enregistrer (module Rendez-vous inactif)';
        }

        $known = array_merge(
            array_column($trace['orders'], 'reference'),
            array_column($trace['appointments'], 'reference'),
            array_keys($this->conversationReferences($conversation)),
        );

        $unknown = array_values(array_diff($cited, $known));
        if ($unknown !== []) {
            return 'référence sans commande ni demande correspondante : '.implode(', ', $unknown);
        }

        if ($claimsBooked && ! $this->hasConfirmedAppointment($conversation)) {
            return 'rendez-vous annoncé comme réservé ou confirmé alors que seule l\'entreprise peut le confirmer';
        }

        $recordedThisTurn = $trace['orders'] !== [] || $trace['appointments'] !== [];

        if (($claimsOrder || $claimsRequest) && ! $recordedThisTurn && $cited === []) {
            return $claimsOrder
                ? 'commande annoncée comme enregistrée sans appel réussi à create_order'
                : 'demande de rendez-vous annoncée comme enregistrée sans appel réussi à create_appointment';
        }

        return null;
    }

    /**
     * Références de commande ou de rendez-vous citées dans un texte (« Référence : 871086 », « réf. 3F2A9C »…).
     *
     * @return array<int, string>
     */
    private function citedOrderReferences(string $text): array
    {
        // Soit un identifiant après « : », « # » ou « n° », soit 6 caractères hexadécimaux
        // (format des références réelles, qui peuvent ne contenir que des lettres).
        preg_match_all(
            '/\br[ée]f(?:[ée]rence)?\.?\s*(?:de\s+(?:la\s+|votre\s+)?(?:commande|demande|r[ée]servation)\s*)?'
            .'(?:(?:n[°o]\.?|[:#])\s*[*_]*\s*([a-z0-9][a-z0-9-]{2,15})|[*_]*\s*([0-9a-f]{6}))\b/iu',
            $text,
            $matches,
        );

        // « Votre commande 871086… », « votre demande DAFACF… » : référence citée sans le mot « référence ».
        preg_match_all('/\b(?:commande|demande)\s+(?:n[°o]\.?\s*)?#?[*_]*([0-9a-f]{6})\b/iu', $text, $direct);

        $references = array_filter(array_merge($matches[1], $matches[2], $direct[1]));

        return array_values(array_unique(array_map('strtoupper', $references)));
    }

    /**
     * Indiquer si un texte annonce une commande comme enregistrée, confirmée ou validée.
     */
    private function claimsOrderRecorded(string $text): bool
    {
        if (preg_match('/\bnum[ée]ro de commande\b/iu', $text) === 1) {
            return true;
        }

        return $this->hasAffirmativeMatch(
            '/\bcommandes?\b[^.!?\n]{0,60}?\b(?:enregistr[ée]+e?s?|confirm[ée]+e?s?|valid[ée]+e?s?|prise en compte)\b/iu',
            $text,
        );
    }

    /**
     * Indiquer si un texte annonce une demande de rendez-vous comme enregistrée.
     */
    private function claimsAppointmentRequestRecorded(string $text): bool
    {
        $patterns = [
            '/\b(?:demandes?|rendez-vous|rdv|s[ée]ances?|r[ée]servations?)\b[^.!?\n]{0,60}?\b(?:enregistr[ée]+e?s?|prise en compte)\b/iu',
            // « Votre rendez-vous est bien noté », « Rendez-vous pris pour samedi » ; pas « votre demande est
            // notée », ni « les rendez-vous sont pris le samedi » ou « ce créneau est déjà pris ».
            '/(?:^|[.!?]\s+|\b(?:votre|ta|ton|le|la|ce|cette)\s+)(?:rendez-vous|rdv|s[ée]ance|r[ée]servation)\b'
                .'(?:(?!d[ée]j[àa])[^.!?\n]){0,40}?\b(?:not[ée]+e?|pris|prise|retenue?|inscrite?)\b/imu',
            // « J'ai bien noté votre rendez-vous », « je note votre séance de samedi » (pas une question de récapitulatif).
            '/\b(?:(?:j[\'’]ai|je vous ai|nous avons|on a)\s+(?:bien\s+|donc\s+)?(?:enregistr[ée]|not[ée]|inscrit|r[ée]serv[ée]|pris|retenu)'
                .'|(?:je|nous)\s+(?:vous\s+)?(?:bien\s+|donc\s+)?(?:enregistre|note|inscris|notons|enregistrons|inscrivons))\s+'
                .'(?:votre|le|la|ce|cette|un|une)\s+(?:demande\s+de\s+)?(?:rendez-vous|rdv|s[ée]ance|r[ée]servation|cr[ée]neau)\b(?![^.!?\n]*\?)/iu',
            // « C'est noté pour samedi à 15h ».
            '/\bc[\'’]est\s+(?:bien\s+|donc\s+)?not[ée]\s+pour\s+(?:le\s+|ce\s+)?(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche|demain|apr[èe]s-demain|aujourd|ce\s+soir|\d)/iu',
        ];

        foreach ($patterns as $pattern) {
            if ($this->hasAffirmativeMatch($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indiquer si un texte présente un rendez-vous comme réservé ou confirmé
     * (« rendez-vous confirmé », « séance réservée », « je vous confirme le rendez-vous »…).
     */
    private function claimsAppointmentBooked(string $text): bool
    {
        $booked = $this->hasAffirmativeMatch(
            '/\b(?:rendez-vous|rdv|s[ée]ances?|cr[ée]neaux?|cr[ée]neau|r[ée]servations?)\b[^.!?\n]{0,60}?\b(?:r[ée]serv|confirm|valid|bloqu|fix|cal|programm|planifi)[ée]+e?s?\b/iu',
            $text,
        );

        // « Je vous confirme le rendez-vous », « je vous bloque le créneau », sauf dans une question de récapitulatif.
        return $booked || preg_match(
            '/\b(?:je|nous)\s+(?:vous\s+)?(?:ai\s+|avons\s+)?(?:bien\s+)?(?:confirm|r[ée]serv|bloqu|cal|programm|planifi)\w*\s+(?:votre|le|la|ce|cette)\s+(?:rendez-vous|rdv|s[ée]ance|cr[ée]neau)\b(?![^.!?\n]*\?)/iu',
            $text,
        ) === 1;
    }

    /**
     * Vrai si l'expression trouve une annonce affirmative : « sera enregistrée après
     * confirmation » ou « n'a pas été enregistrée » ne sont pas des annonces.
     */
    private function hasAffirmativeMatch(string $pattern, string $text): bool
    {
        preg_match_all($pattern, $text, $matches);

        foreach ($matches[0] as $match) {
            if (preg_match('/\b(?:sera|seront|serait|pourra|dès que|une fois|après|avant|si|pas|aucune?|jamais|n[\'’](?:a|est|ont))\b/iu', $match) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Références des commandes et demandes de rendez-vous de cette conversation,
     * avec leur nature (order ou appointment).
     *
     * @return array<string, string>
     */
    private function conversationReferences(?Conversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        $references = [];

        foreach (Order::query()->where('conversation_id', $conversation->id)->pluck('id') as $id) {
            $references[strtoupper(substr($id, -6))] = 'order';
        }

        foreach (Appointment::query()->where('conversation_id', $conversation->id)->pluck('id') as $id) {
            $references[strtoupper(substr($id, -6))] = 'appointment';
        }

        return $references;
    }

    /**
     * Indiquer si l'entreprise a confirmé un rendez-vous dans cette conversation.
     */
    private function hasConfirmedAppointment(?Conversation $conversation): bool
    {
        return $conversation !== null && Appointment::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'confirmed')
            ->exists();
    }

    /**
     * Note interne ajoutée à un message passé du bot qui parle d'une commande ou
     * d'une demande de rendez-vous, pour que le modèle sache si elle a réellement
     * été enregistrée et n'imite pas une confirmation passée.
     *
     * @param  array<string, string>  $knownReferences  référence => order|appointment
     */
    private function orderNote(string $content, ?string $metadata, array $knownReferences): string
    {
        $meta = json_decode((string) $metadata, true) ?: [];

        $recorded = [];
        foreach (array_column((array) ($meta['orders'] ?? []), 'reference') as $reference) {
            $recorded[$reference] = 'order';
        }
        foreach (array_column((array) ($meta['appointments'] ?? []), 'reference') as $reference) {
            $recorded[$reference] = 'appointment';
        }

        $cited = $this->citedOrderReferences($content);
        $claimsOrder = $this->claimsOrderRecorded($content);
        $claimsRequest = $this->claimsAppointmentRequestRecorded($content);

        if ($recorded === [] && $cited === [] && ! $claimsOrder && ! $claimsRequest) {
            return '';
        }

        $valid = $recorded + array_intersect_key($knownReferences, array_flip($cited));
        $invalid = array_values(array_diff($cited, array_keys($knownReferences), array_keys($recorded)));

        if ($valid !== [] && $invalid === []) {
            $labels = [];
            foreach ($valid as $reference => $kind) {
                $labels[] = $kind === 'order'
                    ? "commande {$reference} réellement enregistrée par l'outil create_order"
                    : "demande de rendez-vous {$reference} réellement enregistrée par l'outil create_appointment (créneau à confirmer par l'entreprise)";
            }

            return "\n[Note système : ".implode(' ; ', $labels).'.]';
        }

        $what = $claimsRequest && ! $claimsOrder ? 'aucune demande de rendez-vous' : 'aucune commande';

        return "\n[Note système : {$what} n'a été enregistrée pour ce message"
            .($invalid !== [] ? ' ; la référence '.implode(', ', $invalid).' n\'existe pas' : '')
            .'. Ne t\'en sers pas comme modèle.]';
    }

    /**
     * Consigne de correction envoyée à Claude quand sa réponse a été bloquée.
     */
    private function orderClaimCorrection(string $reason, bool $appointmentsAvailable = true): string
    {
        if (! $appointmentsAvailable && str_contains($reason, 'module Rendez-vous inactif')) {
            return "[Message système, pas du client : ne le mentionne pas dans ta réponse] Ta réponse précédente n'a pas été envoyée ({$reason}). "
                ."Tu ne peux pas enregistrer de rendez-vous : ne dis jamais qu'un rendez-vous ou une réservation est noté, enregistré, pris, réservé ou confirmé. "
                ."Dis au client que tu transmets sa demande au gérant, qui reviendra vers lui, puis ajoute JE_NE_SAIS_PAS seul sur la dernière ligne. "
                .'Réécris ta réponse au client en conséquence.';
        }

        return "[Message système, pas du client : ne le mentionne pas dans ta réponse] Ta réponse précédente n'a pas été envoyée ({$reason}). "
            ."N'annonce une commande ou une demande de rendez-vous comme enregistrée qu'après un appel réussi à l'outil correspondant "
            ."dans cet échange (create_order ou create_appointment) : si le client a clairement confirmé le récapitulatif complet "
            ."et que l'outil n'a pas encore été appelé, appelle-le maintenant. Ne cite que des références renvoyées par un outil. "
            ."Ne dis jamais qu'un rendez-vous est réservé ou confirmé : dis que la demande est enregistrée et que le créneau sera confirmé. "
            .'Réécris ta réponse au client en conséquence.';
    }

    /**
     * Trace des appels d'outils à enregistrer dans le metadata du message sortant.
     *
     * @param  array{orders: array<int, array{id: string, reference: string}>, appointments: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool, usage: array<string, int>}  $trace
     * @return array<string, mixed>
     */
    private function traceForMetadata(array $trace): array
    {
        return array_filter([
            'orders' => $trace['orders'],
            'appointments' => $trace['appointments'],
            'tool_errors' => $trace['tool_errors'],
            'blocked_claim' => $trace['blocked_claim'],
            // Tokens consommés pour produire ce message (coût calculé par AiCostService).
            'ai_usage' => $trace['usage']['calls'] > 0 ? ['model' => $this->model, ...$trace['usage']] : null,
        ]);
    }

    /**
     * Appeler l'API Messages d'Anthropic et renvoyer la réponse décodée.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function callClaude(array $request): array
    {
        return Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', $request)
            ->throw()
            ->json();
    }

    /**
     * Concaténer les blocs de texte d'une réponse (une réponse avec outils peut
     * contenir plusieurs blocs, et pas forcément un texte en premier).
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): string
    {
        $text = collect($payload['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n\n");

        // Les notes internes de l'historique ne doivent jamais atteindre le client.
        return trim(preg_replace('/^[ \t]*\[Note système[^\]]*\][ \t]*$/mu', '', $text) ?? $text);
    }

    /**
     * Exécuter les appels d'outils d'une réponse et produire les tool_result,
     * tous dans un même message utilisateur.
     *
     * @param  array<int, array<string, mixed>>  $content
     * @param  array{orders: array<int, array{id: string, reference: string}>, appointments: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool, usage: array<string, int>}  $trace
     * @return array<int, array<string, mixed>>
     */
    private function runTools(array $content, Business $business, ?Conversation $conversation, array &$trace): array
    {
        $results = [];

        // Un outil n'est exécuté que si son module est actif (il n'est d'ailleurs proposé qu'à cette condition).
        $available = array_filter([
            'create_order' => $business->hasModule('orders'),
            'create_appointment' => $business->hasModule('appointments'),
            'search_catalog' => $business->hasModule('catalog'),
        ]);

        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $result = ['type' => 'tool_result', 'tool_use_id' => $block['id']];
            $tool = $block['name'];

            Log::info("AIResponseService: appel de l'outil {$tool} reçu", [
                'business_id' => $business->id,
                'conversation_id' => $conversation?->id,
                'available' => isset($available[$tool]),
                'fields' => array_keys((array) ($block['input'] ?? [])),
            ]);

            // Consulter le catalogue ne demande pas de conversation ; enregistrer, si.
            if (! isset($available[$tool]) || ($conversation === null && $tool !== 'search_catalog')) {
                $trace['tool_errors'][] = "Outil inconnu : {$tool}.";
                $results[] = $result + ['content' => "Outil inconnu : {$tool}.", 'is_error' => true];

                continue;
            }

            $input = (array) ($block['input'] ?? []);

            if ($tool === 'search_catalog') {
                $found = $this->catalog->search($business, (string) ($input['query'] ?? ''), array_intersect_key($input, array_flip(['size', 'color', 'category', 'max_price'])));

                Log::info('AIResponseService: search_catalog', [
                    'business_id' => $business->id,
                    'conversation_id' => $conversation?->id,
                    'filters' => array_keys(array_intersect_key($input, array_flip(['size', 'color', 'category', 'max_price']))),
                    'results' => count($found),
                ]);

                $results[] = $result + ['content' => json_encode($found === []
                    ? ['results' => [], 'note' => "Aucun article du catalogue ne correspond. Ne l'invente pas : pose une question ou applique la règle d'escalade."]
                    : ['results' => $found], JSON_UNESCAPED_UNICODE)];

                continue;
            }

            try {
                if ($tool === 'create_order') {
                    $order = $this->orderService->createFromAi($business, $conversation, $input);
                    $reference = $order->reference();
                    $trace['orders'][] = ['id' => $order->id, 'reference' => $reference];

                    $payload = [
                        'status' => 'commande enregistrée',
                        'reference' => $reference,
                        'total_amount' => $order->total_amount,
                        'currency' => 'FCFA',
                        'items' => $order->items,
                    ];
                } else {
                    $appointment = $this->appointmentService->createFromAi($business, $conversation, $input);
                    $reference = $appointment->reference();
                    $trace['appointments'][] = ['id' => $appointment->id, 'reference' => $reference];

                    $payload = [
                        'status' => 'demande de rendez-vous enregistrée, créneau à confirmer par l\'entreprise',
                        'reference' => $reference,
                        'service' => $appointment->service,
                        'requested_date' => $appointment->requested_date->format('Y-m-d'),
                        'requested_time' => $appointment->requested_time,
                        'price' => $appointment->price,
                    ];
                }

                // Pas de données client dans les logs : seulement les clés reçues.
                Log::info("AIResponseService: {$tool} a enregistré une demande", [
                    'business_id' => $business->id,
                    'conversation_id' => $conversation->id,
                    'reference' => $reference,
                    'fields' => array_keys($input),
                ]);

                $results[] = $result + ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)];
            } catch (InvalidArgumentException $e) {
                $trace['tool_errors'][] = $e->getMessage();

                Log::warning("AIResponseService: {$tool} refusé", [
                    'business_id' => $business->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    'fields' => array_keys($input),
                ]);

                $results[] = $result + ['content' => $e->getMessage(), 'is_error' => true];
            } catch (\Throwable $e) {
                // Erreur technique (base de données…) : Claude doit le savoir, sinon il annonce
                // une demande enregistrée ; la réponse entière n'est pas perdue pour autant.
                // Pas de getMessage() : une QueryException contient les valeurs du client.
                $trace['tool_errors'][] = "Erreur technique de {$tool}.";

                Log::error("AIResponseService: {$tool} a échoué (erreur technique)", [
                    'business_id' => $business->id,
                    'conversation_id' => $conversation->id,
                    'exception' => $e::class,
                    'fields' => array_keys($input),
                ]);

                $results[] = $result + [
                    'content' => "Erreur technique : rien n'a été enregistré. N'annonce aucun enregistrement ; dis au client que tu vérifies et ajoute JE_NE_SAIS_PAS seul sur la dernière ligne.",
                    'is_error' => true,
                ];
            }
        }

        return $results;
    }

    /**
     * Définition de l'outil create_appointment.
     *
     * @return array<string, mixed>
     */
    private function createAppointmentTool(): array
    {
        return [
            'name' => 'create_appointment',
            'description' => "Enregistre une demande de rendez-vous du client. Le créneau reste à confirmer par l'entreprise. "
                ."À appeler une seule fois, et uniquement après que le client a répondu clairement « oui » au récapitulatif complet "
                .'(prestation, date, heure, et lieu, participants et prix s\'il y a lieu).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Prestation demandée, telle qu\'elle figure dans le contexte.'],
                    'requested_date' => ['type' => 'string', 'description' => 'Date souhaitée au format AAAA-MM-JJ (jamais dans le passé).'],
                    'requested_time' => ['type' => 'string', 'description' => 'Heure ou moment souhaité, par exemple « 15h » ou « après-midi ».'],
                    'customer_name' => ['type' => 'string', 'description' => 'Nom du client.'],
                    'location' => ['type' => 'string', 'description' => 'Lieu de la prestation, si elle n\'a pas lieu chez l\'entreprise.'],
                    'participants' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Nombre de personnes concernées, si utile.'],
                    'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Prix en FCFA indiqué dans le contexte pour cette prestation. À omettre s\'il n\'y figure pas.'],
                    'notes' => ['type' => 'string', 'description' => 'Précisions utiles du client, facultatif.'],
                ],
                'required' => ['service', 'requested_date', 'requested_time', 'customer_name'],
            ],
        ];
    }

    /**
     * Règles de prise de rendez-vous ajoutées au prompt quand l'outil est disponible.
     */
    private function appointmentInstructions(Business $business): string
    {
        $today = CarbonImmutable::now($this->businessTimezone($business))->locale('fr')->isoFormat('dddd D MMMM YYYY');

        return <<<PROMPT
# Prise de rendez-vous
Nous sommes le {$today}.
Tu peux enregistrer une demande de rendez-vous avec l'outil create_appointment. Le créneau n'est jamais garanti : c'est l'entreprise qui le confirme ensuite.
1. Collecte les informations manquantes, sans redemander ce que le client a déjà donné : la prestation, la date, l'heure ou le moment de la journée, son nom, et selon la prestation le lieu et le nombre de participants. Ne propose que des prestations présentes dans le contexte ; sinon, applique la règle d'escalade.
2. Convertis les dates relatives (« demain », « samedi prochain ») en date précise à partir de la date du jour, et répète-la au client en toutes lettres (par exemple « samedi 10 octobre »). Ne propose jamais une date passée.
3. Utilise uniquement le prix indiqué dans le contexte pour cette prestation. N'invente JAMAIS un prix : s'il n'y figure pas, n'annonce aucun prix et laisse price vide.
4. Fais un récapitulatif : prestation, date en toutes lettres, heure, lieu et participants s'il y a lieu, prix s'il est connu. Termine en demandant une confirmation explicite, par exemple « J'enregistre votre demande ? ».
5. N'appelle create_appointment qu'après un « oui » clair du client à ce récapitulatif. Si le client modifie quelque chose, refais le récapitulatif et redemande confirmation. N'appelle jamais l'outil deux fois pour la même demande.
6. Une fois l'outil exécuté, dis au client que sa demande est enregistrée, donne la référence renvoyée par l'outil, et précise que le créneau lui sera confirmé très vite. Ne dis JAMAIS que le rendez-vous est réservé, confirmé, bloqué ou garanti, et ne décris aucune procédure absente du contexte (appel, acompte, rappel…).
7. Si l'outil renvoie une erreur, demande au client l'information manquante ou une autre date. N'annonce jamais une demande comme enregistrée si l'outil ne l'a pas confirmé dans ce même échange.
PROMPT;
    }

    /**
     * Définition de l'outil create_order, limitée aux modes de remise activés.
     *
     * @return array<string, mixed>
     */
    private function createOrderTool(Business $business, bool $withCatalog = false): array
    {
        return [
            'name' => 'create_order',
            'description' => "Enregistre la commande du client. À appeler une seule fois, et uniquement après que le client a "
                ."répondu clairement « oui » au récapitulatif complet (articles, quantités, prix unitaires, total, mode de remise, paiement). "
                .'Les prix unitaires doivent provenir du contexte ; le total est recalculé par le serveur.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Articles commandés, y compris une ligne « Frais de livraison » si le contexte en indique (jamais pour un retrait en boutique).',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Nom de l\'article tel qu\'il figure dans le contexte.'],
                                'options' => ['type' => 'string', 'description' => 'Options choisies (taille, couleur…), vide si aucune.'],
                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                                'unit_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Prix unitaire en FCFA, tel qu\'indiqué dans le contexte.'],
                            ] + ($withCatalog ? ['variant_id' => ['type' => 'string', 'description' => 'Identifiant de la variante renvoyé par search_catalog, pour un article du catalogue (le prix enregistré est alors celui du catalogue).']] : []),
                            'required' => ['name', 'quantity', 'unit_price'],
                        ],
                    ],
                    'fulfillment_type' => [
                        'type' => 'string',
                        'enum' => $business->enabledFulfillmentTypes(),
                        'description' => 'Mode de remise choisi par le client : delivery (livraison à domicile), pickup (retrait en boutique), shipping (expédition).',
                    ],
                    'customer_name' => ['type' => 'string', 'description' => 'Nom du client.'],
                    'delivery_city' => ['type' => 'string', 'description' => 'Ville de livraison ou d\'expédition. Obligatoire pour delivery et shipping, à omettre pour pickup.'],
                    'delivery_address' => ['type' => 'string', 'description' => 'Adresse ou quartier de livraison. Obligatoire pour delivery, facultatif pour shipping, à omettre pour pickup.'],
                    'pickup_time' => ['type' => 'string', 'description' => 'Pour un retrait : moment de passage indiqué par le client, s\'il l\'a précisé.'],
                    'payment_method' => ['type' => 'string', 'description' => 'Mode de paiement choisi parmi ceux indiqués dans le contexte.'],
                    'notes' => ['type' => 'string', 'description' => 'Précisions utiles du client, facultatif.'],
                ],
                'required' => ['items', 'fulfillment_type', 'customer_name', 'payment_method'],
            ],
        ];
    }

    /**
     * Règles de prise de commande ajoutées au prompt quand l'outil est disponible.
     */
    private function orderInstructions(Business $business, bool $withCatalog = false): string
    {
        $enabled = $business->enabledFulfillmentTypes();

        $modes = implode("\n", array_map(
            fn (string $type) => '- '.self::FULFILLMENT_LABELS[$type],
            $enabled,
        ));

        // Sans expédition, une ville où les documents disent « nous livrons » reste une livraison.
        if (in_array('delivery', $enabled, true) && ! in_array('shipping', $enabled, true)) {
            $modes .= "\nL'expédition n'est pas proposée en tant que telle : si le contexte indique que la boutique livre dans une ville "
                ."(par exemple « Nous livrons à Bobo-Dioulasso »), traite la commande comme une livraison (delivery) vers cette ville, "
                .'avec les frais, délais et conditions de paiement indiqués dans le contexte, au lieu d\'escalader.';
        }

        return <<<PROMPT
# Prise de commande
Modes de remise proposés par la boutique (n'en propose jamais d'autre) :
{$modes}

Tu peux enregistrer une commande avec l'outil create_order. Procède ainsi :
1. Collecte les informations manquantes, sans redemander ce que le client a déjà donné : les articles (nom, options comme la taille ou la couleur si elles existent, quantité), le nom du client, le mode de remise et le mode de paiement. Pose une seule question par message, jamais une liste de questions : la suivante viendra après sa réponse. Ne choisis jamais une option ou une quantité à sa place : demande-la. Si le client n'a pas dit comment il souhaite recevoir sa commande, demande-le-lui en citant uniquement les modes proposés ci-dessus. Ne propose que des articles, options, villes et modes de paiement présents dans le contexte ; sinon, applique la règle d'escalade.
2. Selon le mode de remise : pour une livraison, demande la ville et l'adresse ; pour une expédition, demande la ville de destination ; pour un retrait en boutique, ne demande pas d'adresse, mais donne au client l'adresse et les horaires de la boutique figurant dans les informations générales (ne les invente pas s'ils n'y figurent pas).
3. Utilise uniquement les prix du contexte, ou ceux d'un récapitulatif déjà fait dans cette conversation. N'invente JAMAIS un prix absent : dans ce cas, n'enregistre pas la commande et applique la règle d'escalade. Si le contexte indique des frais de livraison ou d'expédition pour la ville du client, ajoute-les comme une ligne « Frais de livraison » (quantité 1). Ne facture JAMAIS de frais de livraison pour un retrait en boutique.
4. Fais un récapitulatif clair : chaque article avec sa quantité et son prix unitaire, le total (somme des quantités × prix unitaires), le mode de remise et le paiement. Termine en demandant une confirmation explicite, par exemple « Je confirme la commande ? ».
5. N'appelle create_order qu'après un « oui » clair du client à ce récapitulatif. Si le client modifie quelque chose, refais le récapitulatif et redemande confirmation. N'appelle jamais l'outil deux fois pour la même commande.
6. Une fois l'outil exécuté, confirme la commande au client : donne la référence et le total renvoyés par l'outil, puis un court récapitulatif (articles et quantités, mode de remise, paiement). Termine par une phrase neutre, par exemple « Nous revenons vers vous très vite pour finaliser la livraison et le paiement » (ou « le retrait et le paiement » pour un retrait). Ne décris JAMAIS une procédure qui ne figure pas dans le contexte : pas d'appel d'un conseiller, pas d'heure ou de jour de livraison, pas de modalités de paiement (numéro, lien, moment du paiement) que le contexte n'indique pas.
7. N'annonce jamais une commande comme enregistrée si l'outil ne l'a pas confirmé dans ce même échange ; s'il renvoie une erreur, demande au client l'information manquante.
8. La référence d'une commande vient uniquement du résultat de l'outil create_order. N'invente jamais de référence et ne réutilise jamais celle d'une commande précédente pour une nouvelle demande : chaque nouvelle commande passe par un nouveau récapitulatif, une nouvelle confirmation et un nouvel appel à l'outil.
9. Si un récapitulatif attend encore une réponse du client et qu'il demande autre chose (un autre article, une autre ville, un autre mode de remise…), demande-lui d'abord s'il souhaite ajouter cela à sa commande en cours ou remplacer sa commande, puis refais le récapitulatif en conséquence.
10. Les notes « [Note système …] » de l'historique sont internes : elles indiquent si une commande a réellement été enregistrée. Ne les recopie jamais au client.
PROMPT.($withCatalog ? "\n11. Pour un article du catalogue, mets dans la ligne le variant_id renvoyé par search_catalog (la variante choisie par le client : taille, couleur…). N'enregistre jamais une variante en rupture. Les articles et leurs prix viennent uniquement du catalogue, jamais d'un document." : '');
    }

    /**
     * Messages de la session en cours, du plus ancien au plus récent, message
     * courant compris : on remonte jusqu'à la première pause de plus de
     * services.anthropic.session_gap_hours entre deux messages, avec au plus
     * HISTORY_MAX_MESSAGES messages avant le message courant.
     *
     * @return Collection<int, object>
     */
    private function sessionRows(?string $conversationId): Collection
    {
        if ($conversationId === null) {
            return collect();
        }

        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_MAX_MESSAGES + 1)
            ->get(['direction', 'content', 'metadata', 'created_at']);

        $gapSeconds = (float) config('services.anthropic.session_gap_hours', 6) * 3600;

        $session = [];
        $newer = null;

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse($row->created_at);

            if ($newer !== null && $newer->getTimestamp() - $at->getTimestamp() > $gapSeconds) {
                break;
            }

            $session[] = $row;
            $newer = $at;
        }

        return collect(array_reverse($session));
    }

    /**
     * Résumé factuel des commandes et demandes de rendez-vous encore en attente
     * de cette conversation, pour que le bot puisse répondre si le client en
     * parle alors que l'échange où elles ont été faites n'est plus dans l'historique.
     */
    private function pendingRequestsSummary(?Conversation $conversation): ?string
    {
        if ($conversation === null) {
            return null;
        }

        $lines = [];

        $orders = Order::query()->where('conversation_id', $conversation->id)->where('status', 'new')->orderBy('created_at')->get();
        foreach ($orders as $order) {
            $items = implode(', ', array_map(
                fn (array $item) => "{$item['quantity']} × {$item['name']}".(! empty($item['options']) ? " ({$item['options']})" : ''),
                $order->items ?? [],
            ));
            $mode = match ($order->fulfillment_type) {
                'pickup' => 'retrait en boutique',
                'shipping' => 'expédition'.($order->delivery_city ? " vers {$order->delivery_city}" : ''),
                default => 'livraison'.($order->delivery_city ? " à {$order->delivery_city}" : ''),
            };

            $lines[] = "- Commande {$order->reference()} du {$order->created_at->format('d/m/Y')} : {$items}, total "
                .number_format($order->total_amount, 0, ',', ' ')." FCFA, {$mode}. Statut : enregistrée, pas encore traitée par l'entreprise.";
        }

        $appointments = Appointment::query()->where('conversation_id', $conversation->id)->where('status', 'requested')->orderBy('created_at')->get();
        foreach ($appointments as $appointment) {
            $lines[] = "- Demande de rendez-vous {$appointment->reference()} : {$appointment->service}, "
                .$appointment->requested_date->locale('fr')->isoFormat('dddd D MMMM')." à {$appointment->requested_time}. "
                ."Statut : en attente de confirmation par l'entreprise.";
        }

        if ($lines === []) {
            return null;
        }

        return "# Demandes en cours de ce client\n"
            ."Ces demandes ont été enregistrées et ne sont pas encore traitées. N'en parle pas de toi-même : "
            ."utilise ces informations seulement si le client les évoque, sans rien promettre de plus que leur statut.\n"
            .implode("\n", $lines);
    }

    /**
     * Récupérer le contexte pertinent (chunks de documents + réponses apprises).
     *
     * @return Collection<int, object>
     */
    public function findRelevantContext(Business $business, string $question, int $limit = 5): Collection
    {
        $vector = $this->questionVector($question);

        $chunks = collect(DB::select(
            'SELECT id, content, 1 - (embedding <=> ?::vector) as similarity
             FROM document_chunks
             WHERE business_id = ?
             ORDER BY similarity DESC
             LIMIT ?',
            [$vector, $business->id, $limit],
        ))->map(function ($row) {
            $row->source = 'document';

            return $row;
        });

        $learned = collect(DB::select(
            'SELECT id, question, answer, 1 - (question_embedding <=> ?::vector) as similarity
             FROM learned_responses
             WHERE business_id = ?
             ORDER BY similarity DESC
             LIMIT ?',
            [$vector, $business->id, $limit],
        ))->map(function ($row) {
            $row->content = "Q : {$row->question}\nR : {$row->answer}";
            $row->source = 'learned_response';

            return $row;
        });

        return $chunks
            ->merge($learned)
            ->filter(fn ($row) => (float) $row->similarity > self::SIMILARITY_THRESHOLD)
            ->sortByDesc('similarity')
            ->values();
    }

    /**
     * La réponse tutoie-t-elle le client (« tu », « portes-tu », « t'as », « ta commande »…) ?
     * Les lignes techniques (MEDIA:, JE_NE_SAIS_PAS) et les citations « … » sont ignorées.
     */
    private function usesTu(string $text): bool
    {
        $text = (string) preg_replace(['/^\s*MEDIA\s*:.*$/mi', '/«[^»]*»/u'], '', $text);

        return preg_match('/(?<![\p{L}\'’-])(?:(?:tu|toi|te|tes|ton|ta)(?![\p{L}\'’])|t[\'’](?=\p{L}))|-(?:tu|toi)\b/iu', $text) === 1;
    }

    /**
     * Consignes quand le client a envoyé une ou plusieurs photos.
     */
    private function photoInstructions(int $shown, int $unreadable, bool $ordersAvailable): string
    {
        $photos = match (true) {
            $shown === 0 => "Le client a envoyé {$unreadable} photo(s) qui n'ont pas pu être affichées : demande-lui simplement ce qu'il souhaite, ou applique la règle d'escalade.",
            $shown === 1 => 'Le client a envoyé une photo, jointe à son message.',
            default => "Le client a envoyé {$shown} photos, jointes à son message : traite-les ensemble.",
        };

        if ($shown > 0 && $unreadable > 0) {
            $photos .= " {$unreadable} autre(s) photo(s) n'ont pas pu être affichées.";
        }

        $order = $ordersAvailable ? ' Tu peux ensuite proposer de passer commande, comme pour une demande écrite.' : '';

        return <<<PROMPT
# Photo(s) du client
{$photos}
1. Si une photo est la capture ou la photo d'un reçu de paiement (Orange Money, Moov Money, Wave, transfert, virement, dépôt…) : ne confirme JAMAIS le paiement ni son montant, et ne dis jamais qu'il est reçu ou validé. Écris seulement PREUVE_PAIEMENT, seul sur la dernière ligne : un message adapté est envoyé au client et le gérant vérifie.
2. Sinon, cherche l'article photographié dans le contexte et dans les médias du business (titres, descriptions, mots-clés). Si un article correspond clairement, donne son nom, son prix et sa disponibilité tels qu'ils figurent dans le contexte, sans rien ajouter qui n'y figure pas.{$order}
3. Si rien ne correspond clairement, ou au moindre doute, dis-le simplement et pose une seule question pour préciser (nom de l'article, taille, couleur…), ou applique la règle d'escalade. N'invente jamais un article, un prix ou une disponibilité, et ne devine pas un prix d'après la photo.
4. Ne décris pas la photo au client : réponds à ce qu'il cherche.
PROMPT;
    }

    /**
     * Médias à envoyer, sans doublon : un même fichier (même adresse, même chemin, ou même
     * type et même taille au octet près : la photo téléversée deux fois) ou une autre photo
     * du même article du catalogue ne part qu'une fois dans une réponse.
     *
     * @param  Collection<int, \App\Models\BusinessMedia>  $media
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function distinctMedia(Business $business, Collection $media, array $ids): array
    {
        $productByMedia = [];
        if (count($ids) > 1 && $business->hasModule('catalog')) {
            foreach ($business->products()->whereNotNull('media_ids')->get(['id', 'media_ids']) as $product) {
                foreach ((array) $product->media_ids as $mediaId) {
                    $productByMedia[$mediaId] ??= $product->id;
                }
            }
        }

        $seen = [];
        $kept = [];

        foreach ($ids as $id) {
            $item = $media->firstWhere('id', $id);
            $keys = array_filter([
                "id:{$id}",
                ! empty($item?->public_url) ? "url:{$item->public_url}" : null,
                ! empty($item?->file_path) ? "file:{$item->file_path}" : null,
                (int) ($item?->file_size ?? 0) > 0 ? "size:{$item->mime_type}:{$item->file_size}" : null,
                isset($productByMedia[$id]) ? "product:{$productByMedia[$id]}" : null,
            ]);

            if (array_intersect($keys, $seen) !== []) {
                continue;
            }

            $seen = [...$seen, ...$keys];
            $kept[] = $id;
        }

        if (count($kept) < count($ids)) {
            Log::info('AIResponseService: médias en double retirés de la réponse', [
                'business_id' => $business->id,
                'requested' => count($ids),
                'sent' => count($kept),
            ]);
        }

        return $kept;
    }

    /**
     * Recherche dans le catalogue faite avant d'appeler Claude, avec le message du client :
     * les articles, prix et variantes qui font foi sont sous ses yeux avant les documents.
     */
    private function catalogBlock(Business $business, string $searchText, ?string $conversationId): string
    {
        $found = $this->catalog->search($business, $searchText, [], self::CATALOG_PREFETCH_LIMIT);

        Log::info('AIResponseService: catalogue consulté avec la question', [
            'business_id' => $business->id,
            'conversation_id' => $conversationId,
            'results' => count($found),
        ]);

        if ($found === []) {
            return "Catalogue (fait foi) : aucun article ne correspond à ce message. S'il porte sur un article, "
                ."celui-ci n'est pas au catalogue : appelle search_catalog avec d'autres mots (catégorie, usage) pour proposer des articles proches, "
                ."sans jamais vendre un article ou un prix trouvé seulement dans les documents.\n\n";
        }

        return "Catalogue (fait foi), articles qui correspondent à ce message :\n"
            .json_encode(['results' => $found], JSON_UNESCAPED_UNICODE)."\n\n";
    }

    /**
     * Tous les médias actifs du business (au plus PHOTO_CATALOG_LIMIT), pour reconnaître
     * l'article d'une photo.
     *
     * @return Collection<int, \App\Models\BusinessMedia>
     */
    private function catalogMedia(Business $business): Collection
    {
        return $business->media()->active()->latest()->limit(self::PHOTO_CATALOG_LIMIT)->get();
    }

    /**
     * « Robe wax rouge — modèle Awa (mots-clés : robe, wax) (id: …, type: image) », suivie de
     * l'article du catalogue que montre cette photo, s'il y en a un.
     *
     * @param  array<string, string>  $productsByMedia
     */
    private function catalogLine(object $media, array $productsByMedia = []): string
    {
        $keywords = array_filter(array_map('strval', (array) ($media->keywords ?? [])));

        return trim((string) $media->title)
            .(! empty($media->description) ? ' — '.trim((string) $media->description) : '')
            .($keywords !== [] ? ' (mots-clés : '.implode(', ', $keywords).')' : '')
            ." (id: {$media->id}, type: {$media->type})"
            .(isset($productsByMedia[$media->id]) ? ' → '.$productsByMedia[$media->id] : '');
    }

    /**
     * Article du catalogue illustré par chaque média : « article du catalogue « Robe wax Awa »,
     * 12 500 FCFA, disponible (vérifie la variante avec search_catalog avant de confirmer) ».
     *
     * @return array<string, string>
     */
    private function catalogProductsByMedia(Business $business): array
    {
        $lines = [];

        foreach ($business->products()->where('is_active', true)->whereNotNull('media_ids')->with('variants')->limit(200)->get() as $product) {
            $prices = $product->variants->map(fn (ProductVariant $variant) => $variant->price ?? $product->base_price);
            $availabilities = $product->variants->pluck('availability');
            $availability = match (true) {
                $availabilities->contains(ProductVariant::AVAILABLE) => 'disponible',
                $availabilities->contains(ProductVariant::LOW_STOCK) => 'dernières pièces',
                default => 'en rupture',
            };
            $price = $prices->min() === $prices->max()
                ? number_format((float) $prices->min(), 0, ',', ' ').' FCFA'
                : 'de '.number_format((float) $prices->min(), 0, ',', ' ').' à '.number_format((float) $prices->max(), 0, ',', ' ').' FCFA';

            foreach ((array) $product->media_ids as $mediaId) {
                $lines[$mediaId] ??= "article du catalogue « {$product->name} », {$price}, {$availability} (vérifie la variante avec search_catalog avant de confirmer)";
            }
        }

        return $lines;
    }

    /**
     * Message passé du client qui était une photo, un document… : la légende seule ne
     * dit pas qu'il y avait un fichier (Claude ne revoit pas les anciennes photos).
     */
    private function withMediaLabel(string $content, ?string $metadata): string
    {
        $type = (json_decode((string) $metadata, true) ?: [])['type'] ?? null;

        if (! in_array($type, MediaMessage::TYPES, true) || in_array($content, MediaMessage::PLACEHOLDERS, true)) {
            return $content;
        }

        return MediaMessage::PLACEHOLDERS[$type].' : '.$content;
    }

    /** Texte du client sans les « 📷 Photo » des photos sans légende. */
    private function withoutPhotoPlaceholders(string $question): string
    {
        return trim(implode("\n", array_filter(
            array_map('trim', explode("\n", $question)),
            fn (string $line) => $line !== '' && ! in_array($line, MediaMessage::PLACEHOLDERS, true),
        )));
    }

    /**
     * Récupérer les médias pertinents pour la question du client.
     *
     * D'abord par mots-clés exacts, puis (si besoin) par similarité vectorielle
     * sur l'embedding des mots-clés.
     *
     * @return Collection<int, \App\Models\BusinessMedia>
     */
    public function findRelevantMedia(Business $business, string $question, int $limit = 3): Collection
    {
        $all = $business->media()->active()->get();

        if ($all->isEmpty()) {
            return collect();
        }

        $normalized = $this->normalize($question);
        $matched = collect();

        // 1. Correspondance par mots-clés.
        foreach ($all as $item) {
            foreach ((array) $item->keywords as $keyword) {
                $keywordNormalized = $this->normalize((string) $keyword);

                if ($keywordNormalized !== '' && $this->containsAnyWord($normalized, [$keywordNormalized])) {
                    $matched->push($item);

                    break;
                }
            }
        }

        // 2. Recherche par similarité vectorielle si on n'a pas assez de résultats.
        if ($matched->count() < $limit) {
            try {
                $vector = $this->questionVector($question);

                $rows = DB::select(
                    'SELECT id, 1 - (keywords_embedding <=> ?::vector) as similarity
                     FROM business_media
                     WHERE business_id = ? AND is_active = true AND keywords_embedding IS NOT NULL
                     ORDER BY similarity DESC
                     LIMIT ?',
                    [$vector, $business->id, $limit],
                );

                foreach ($rows as $row) {
                    if ((float) $row->similarity > self::SIMILARITY_THRESHOLD) {
                        $item = $all->firstWhere('id', $row->id);

                        if ($item !== null) {
                            $matched->push($item);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AIResponseService::findRelevantMedia — recherche vectorielle ignorée', [
                    'business_id' => $business->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $matched->unique('id')->take($limit)->values();
    }

    /**
     * Réponses du gérant aux questions les plus proches de celle du client, pour
     * imiter son ton. Vide si le gérant a coupé l'apprentissage ou n'a rien écrit.
     *
     * @return Collection<int, object>
     */
    public function findOwnerExamples(Business $business, string $question, int $limit = self::OWNER_EXAMPLES): Collection
    {
        if (! $business->learnsFromOwner()) {
            return collect();
        }

        try {
            // Évite un embedding (et une requête pgvector) pour un business sans réponse du gérant.
            if (! $business->managerReplies()->exists()) {
                return collect();
            }

            return $this->managerReplies->similarExamples($business, $this->questionVector($question), $limit);
        } catch (\Throwable $e) {
            Log::warning('AIResponseService::findOwnerExamples — exemples du gérant ignorés', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            return collect();
        }
    }

    /**
     * Embedding de la question au format pgvector, calculé une seule fois par réponse
     * (contexte, médias et exemples du gérant).
     */
    private function questionVector(string $question): string
    {
        return $this->vectors[$question] ??= '['.implode(',', $this->embeddingService->embed($question)).']';
    }

    /**
     * @param  Collection<int, object>  $examples
     */
    private function ownerExamplesBlock(Collection $examples): string
    {
        $lines = $examples->values()->map(function (object $example, int $i) {
            $question = mb_substr(trim((string) $example->customer_question), 0, 200);
            $reply = mb_substr(trim((string) $example->reply), 0, 400);

            return ($i + 1).". Client : {$question}\n   Gérant : {$reply}";
        })->implode("\n");

        return "# Exemples de la façon dont le gérant écrit\n"
            ."Réponses écrites par le gérant lui-même à des questions proches. Imite leur ton, leur longueur, "
            ."leur registre et leurs formules. Elles servent au ton, PAS aux faits : prix, disponibilités, délais "
            ."et conditions ont pu changer ; ne reprends un fait que s'il figure dans le contexte fourni avec la question. "
            ."Garde le tutoiement ou le vouvoiement fixé dans les règles absolues, même si le gérant fait autrement dans ces exemples.\n"
            .$lines;
    }

    /**
     * Plafond de tokens de la réponse : court par défaut, plus large quand Claude peut
     * appeler un outil (commande, rendez-vous).
     */
    private function maxTokens(Business $business, bool $withTools): int
    {
        if ($this->legacyStyle) {
            return self::LEGACY_MAX_TOKENS;
        }

        $base = $business->reply_style['length'] === 'very_short' ? self::MAX_TOKENS_VERY_SHORT : self::MAX_TOKENS;

        return $base + ($withTools ? self::MAX_TOKENS_TOOLS_EXTRA : 0);
    }

    /**
     * Tutoiement ou vouvoiement : « tu » seulement si le gérant l'a choisi (Business::tone),
     * jamais d'après son profil appris ni d'après la façon d'écrire du client.
     */
    private function addressOf(Business $business): string
    {
        return $business->tone;
    }

    /**
     * Construire l'historique de conversation au format messages Claude à partir
     * des messages de la session en cours (voir sessionRows), en excluant le
     * message courant qui vient d'être enregistré.
     *
     * @param  Collection<int, object>  $session
     * @return array<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(Collection $session, ?Conversation $conversation, string $currentQuestion): array
    {
        $rows = $session->values();

        // Les messages entrants courants sont déjà en base : on retire les derniers messages du
        // client qui forment la question (plusieurs quand ils ont été regroupés, voir ReplyToCustomer).
        $trailing = [];
        for ($i = $rows->count() - 1; $i >= 0 && $rows[$i]->direction === 'inbound'; $i--) {
            array_unshift($trailing, trim((string) $rows[$i]->content));

            if (implode("\n", $trailing) === trim($currentQuestion)) {
                $rows = $rows->slice(0, $i)->values();

                break;
            }
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $knownReferences = $this->conversationReferences($conversation);

        $messages = [];
        foreach ($rows as $row) {
            $content = trim((string) $row->content);
            if ($content === '') {
                continue;
            }

            $role = $row->direction === 'inbound' ? 'user' : 'assistant';

            if ($role === 'assistant') {
                $content .= $this->orderNote($content, $row->metadata, $knownReferences);
            } else {
                $content = $this->withMediaLabel($content, $row->metadata);
            }

            // Fusionne les messages consécutifs de même rôle (contrainte de l'API).
            if ($messages !== [] && end($messages)['role'] === $role) {
                $messages[count($messages) - 1]['content'] .= "\n".$content;

                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        // L'historique doit commencer par un message "user".
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    /**
     * Répondre aux messages simples (salutations, remerciements, au revoir)
     * sans passer par le RAG ni l'IA.
     *
     * @return array{answer: ?string, confidence: float, should_escalate: bool, context_used: array<int, mixed>}|null
     */
    private function handleSimpleMessage(Business $business, string $question, bool $inConversation = false, bool $awaitsAnswer = false): ?array
    {
        $normalized = $this->normalize($question);

        if ($normalized === '') {
            return null;
        }

        $wordCount = count(explode(' ', $normalized));

        // Au-delà de 7 mots, ce n'est plus un message "simple".
        if ($wordCount > 7) {
            return null;
        }

        $greetings = [
            'bonjour', 'bonsoir', 'salut', 'hello', 'hi', 'hey', 'coucou', 'cc', 'bsr', 'bjr',
            'salam', 'salam aleykoum', 'bonjour bonjour', 'yo', 'kikou', 'ca va', 'ca va bien', 'bonjr',
        ];
        $thanks = [
            'merci', 'ok merci', 'merci beaucoup', 'merci bcp', 'thanks', 'thank you', 'daccord merci',
            'd accord merci', 'ok merci beaucoup', 'merci infiniment', 'nagode', 'merci bien',
            'c est note', 'cest note', 'parfait merci', 'super merci', 'entendu', 'compris',
            'bien recu', 'bien recu merci',
        ];
        $goodbyes = [
            'au revoir', 'aurevoir', 'bye', 'bonne journee', 'bonne soiree', 'bonne nuit', 'a bientot',
            'a plus', 'a plus tard', 'ciao', 'bonne continuation',
        ];
        $affirmations = ['ok', 'oui', 'daccord', 'd accord', 'ok daccord', 'ok d accord'];

        // Mots signalant une vraie demande : on ne court-circuite pas l'IA.
        $requestWords = [
            'prix', 'tarif', 'tarifs', 'combien', 'cout', 'coute', 'horaire', 'horaires', 'adresse',
            'ouvert', 'ferme', 'livraison', 'livrer', 'commande', 'commander', 'reservation', 'reserver',
            'dispo', 'disponible', 'disponibilite', 'donner', 'donnez', 'donne', 'envoyer', 'envoie',
            'envoyez', 'besoin', 'voudrais', 'veux', 'aimerais', 'peux', 'pouvez', 'savoir', 'quand',
            'comment', 'pourquoi', 'quel', 'quelle', 'quels', 'quelles', 'info', 'infos', 'information',
            'informations', 'renseignement', 'renseignements', 'question', 'aide', 'aider', 'probleme',
            'souci', 'menu', 'produit', 'produits', 'service', 'services',
        ];

        $looksLikeRequest = $this->containsAnyWord($normalized, $requestWords);

        $isGreeting = $this->matchesSimple($normalized, $greetings);
        $isThanks = $this->matchesSimple($normalized, $thanks);
        $isGoodbye = $this->matchesSimple($normalized, $goodbyes);
        $isAffirmation = $this->matchesSimple($normalized, $affirmations);

        // Pour les messages de 3 à 7 mots, on accepte la simple contenance d'un
        // mot-clé de remerciement / au revoir, sauf si le message ressemble à une demande.
        if (! $looksLikeRequest && $wordCount >= 3 && $wordCount <= 7) {
            if (! $isThanks && $this->containsAnyWord($normalized, ['merci', 'thanks', 'nagode', 'entendu', 'compris'])) {
                $isThanks = true;
            }

            if (! $isGoodbye && (
                $this->containsAnyWord($normalized, ['bye', 'ciao', 'revoir', 'bientot'])
                || str_contains($normalized, 'bonne nuit')
                || str_contains($normalized, 'bonne journee')
                || str_contains($normalized, 'bonne soiree')
            )) {
                $isGoodbye = true;
            }
        }

        // Le bot vient de poser une question (récapitulatif à confirmer…) : « Ok merci »,
        // « Parfait merci » ou « Oui » y répondent, l'IA doit les traiter.
        if ($awaitsAnswer && ($isThanks || $isAffirmation)) {
            return null;
        }

        // Courtes et sans mise en forme, au tutoiement ou au vouvoiement du gérant.
        $tu = $this->addressOf($business) === 'tu';

        if ($isGreeting) {
            $name = $business->name;
            $hello = $this->resolveGreetingWord($business, $normalized);

            $variants = $tu
                ? ["{$hello} 👋 Je peux t'aider ?", "{$hello} ! Qu'est-ce qu'il te faut ?", "{$hello}, c'est {$name}. Je t'écoute."]
                : ["{$hello} 👋 Comment puis-je vous aider ?", "{$hello} ! Que puis-je faire pour vous ?", "{$hello}, ici {$name}. Je vous écoute."];

            if (! empty($business->custom_greeting)) {
                $variants[0] = trim($business->custom_greeting);
            }

            return $this->simpleResponse($this->pickRandom($variants));
        }

        if ($isThanks) {
            $variants = $tu
                ? ['Avec plaisir !', 'De rien 🙏', 'Je t\'en prie !']
                : ['Avec plaisir !', 'De rien 🙏', 'Je vous en prie !'];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        if ($isGoodbye) {
            $variants = $tu
                ? ['Bonne journée, à bientôt ! 👋', 'À bientôt !', 'Merci, à très vite !']
                : ['Bonne journée, à bientôt ! 👋', 'À bientôt !', 'Merci pour votre confiance, à bientôt !'];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        // Après une réponse du bot, un « oui » / « ok » répond à sa question
        // (par exemple la confirmation d'une commande) : on laisse l'IA le traiter.
        if ($isAffirmation && ! $inConversation) {
            $variants = $tu
                ? ['Très bien ! Autre chose ?', 'Parfait 👍 Il te faut autre chose ?', "D'accord !"]
                : ['Très bien ! Autre chose ?', 'Parfait 👍 Il vous faut autre chose ?', "D'accord !"];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        return null;
    }

    /**
     * Fuseau horaire de l'entreprise (défaut : Africa/Ouagadougou, GMT+0).
     */
    private function businessTimezone(Business $business): string
    {
        return $business->localTimezone();
    }

    /**
     * Heure locale actuelle (0-23) de l'entreprise.
     */
    private function currentHour(Business $business): int
    {
        try {
            return (int) now()->timezone($this->businessTimezone($business))->format('G');
        } catch (\Throwable) {
            return (int) now()->format('G');
        }
    }

    /**
     * Salutation adaptée : priorité au message du client (« Bonsoir » →
     * « Bonsoir »), sinon on se base sur l'heure locale de l'entreprise.
     * Matin/après-midi (5h-18h) → « Bonjour » ; soir/nuit (18h-5h) → « Bonsoir ».
     */
    private function resolveGreetingWord(Business $business, string $normalized): string
    {
        $words = explode(' ', $normalized);

        if (in_array('bonsoir', $words, true) || in_array('bsr', $words, true)) {
            return 'Bonsoir';
        }

        if (in_array('bonjour', $words, true) || in_array('bjr', $words, true) || in_array('bonjr', $words, true)) {
            return 'Bonjour';
        }

        $hour = $this->currentHour($business);

        return ($hour >= 18 || $hour < 5) ? 'Bonsoir' : 'Bonjour';
    }

    /**
     * Décrire le moment de la journée pour le prompt système.
     */
    private function partOfDay(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 12 => 'le matin',
            $hour >= 12 && $hour < 18 => "l'après-midi",
            $hour >= 18 && $hour < 22 => 'le soir',
            default => 'la nuit',
        };
    }

    /**
     * Construire la réponse standard d'un message simple.
     *
     * @return array{answer: string, confidence: float, should_escalate: bool, context_used: array<int, mixed>}
     */
    private function simpleResponse(string $answer): array
    {
        return [
            'answer' => $answer,
            'confidence' => 1.0,
            'should_escalate' => false,
            'context_used' => [],
            'media_ids' => [],
            // Réponse toute faite, sans appel à Claude : non comptée dans les limites.
            'canned' => true,
        ];
    }

    /**
     * Normaliser un message : minuscules, sans accents, sans ponctuation,
     * espaces réduits.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ñ' => 'n',
        ];
        $text = strtr($text, $accents);

        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * Vérifier si le message normalisé correspond à l'un des patterns :
     * égalité exacte, répétition courte (« bonjour bonjour »), ou — pour un
     * message court — présence d'un pattern multi-mots en sous-chaîne.
     *
     * @param  array<int, string>  $patterns
     */
    private function matchesSimple(string $normalized, array $patterns): bool
    {
        if (in_array($normalized, $patterns, true)) {
            return true;
        }

        $words = explode(' ', $normalized);
        $count = count($words);

        // Répétitions courtes : « bonjour bonjour », « cc cc ».
        if ($count <= 3) {
            $unique = array_unique($words);
            if (count($unique) === 1 && in_array($unique[array_key_first($unique)], $patterns, true)) {
                return true;
            }
        }

        // Patterns multi-mots présents en sous-chaîne dans un message court.
        if ($count <= 7) {
            foreach ($patterns as $pattern) {
                if (str_contains($pattern, ' ') && str_contains($normalized, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Vérifier si le message normalisé contient l'un des mots donnés
     * (comparaison mot entier, pas sous-chaîne).
     *
     * @param  array<int, string>  $needles
     */
    private function containsAnyWord(string $normalized, array $needles): bool
    {
        $words = explode(' ', $normalized);

        foreach ($needles as $needle) {
            if (str_contains($needle, ' ')) {
                if (str_contains($normalized, $needle)) {
                    return true;
                }

                continue;
            }

            if (in_array($needle, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function pickRandom(array $items): string
    {
        return $items[array_rand($items)];
    }

    /**
     * Estimer la confiance d'une réponse à partir de la similarité moyenne
     * du contexte utilisé (borné entre 0 et 1).
     *
     * @param  Collection<int, object>  $context
     */
    private function averageSimilarity(Collection $context): float
    {
        if ($context->isEmpty()) {
            return 0.0;
        }

        $average = (float) $context->avg(fn ($row) => (float) $row->similarity);

        return round(max(0.0, min(1.0, $average)), 2);
    }

    /**
     * Construire le prompt système en français pour l'entreprise.
     */
    private function buildSystemPrompt(Business $business): string
    {
        $type = $business->type instanceof \BackedEnum ? $business->type->value : $business->type;

        $identite = "Tu réponds aux clients de *{$business->name}*";
        if (! empty($type)) {
            $identite .= " ({$type})";
        }
        $identite .= '.';

        $infos = [];
        if (! empty($business->description)) {
            $infos[] = "À propos de l'entreprise : {$business->description}";
        }
        if (! empty($business->city)) {
            $infos[] = "Ville : {$business->city}";
        }
        if (! empty($business->address)) {
            $infos[] = "Adresse : {$business->address}";
        }

        $horaires = $this->formatOpeningHours($business->opening_hours);
        if ($horaires !== null) {
            $infos[] = "Horaires d'ouverture : {$horaires}";
        }

        $infosBloc = $infos === []
            ? "Aucune information générale supplémentaire n'a été fournie sur l'entreprise."
            : implode("\n", $infos);

        $accueil = ! empty($business->custom_greeting)
            ? "Message d'accueil défini par l'entreprise (inspire-t'en pour saluer, sans le répéter mot pour mot à chaque fois) :\n\"{$business->custom_greeting}\""
            : "Aucun message d'accueil personnalisé n'a été défini : accueille le client chaleureusement avec tes propres mots.";

        [$personnalite, $langue, $style, $merci] = $this->legacyStyle ? self::LEGACY_STYLE_SECTIONS : self::STYLE_SECTIONS;

        $adresse = $this->addressOf($business) === 'tu'
            ? "- Tutoie toujours le client, même s'il te vouvoie."
            : "- Vouvoie toujours le client, même s'il te tutoie ou écrit de façon familière, et même si le gérant tutoie dans ses propres messages.";

        $prompt = <<<PROMPT
{$identite}

Tu discutes avec un client sur WhatsApp. Tu réponds au nom de {$business->name}, comme un membre de son service client qui connaît bien la maison, au Burkina Faso.

# Ta personnalité
{$personnalite}

# Règles absolues
{$langue}
{$adresse}
- Ne te présente pas de toi-même comme une intelligence artificielle, un robot ou un programme. Mais si le client demande sincèrement s'il parle à une IA ou à un robot, ne le nie JAMAIS : reconnais-le simplement et avec naturel, puis propose ton aide.
- N'invente JAMAIS d'information : prix, stock, disponibilité, horaires, produits, services, délais, villes desservies, conditions, promotions. Utilise uniquement ce qui est écrit dans le contexte fourni plus bas dans la conversation.
- Si une information manque dans le contexte, ne la confirme pas et ne l'infirme pas non plus : ne dis ni « oui » ni « non » (par exemple, si une ville n'est pas mentionnée, ne dis pas que vous y livrez, ni que vous n'y livrez pas). Applique alors la règle d'escalade ci-dessous.
- Ne promets JAMAIS un service qui ne figure pas dans le contexte : par exemple prévenir le client d'un retour en stock, mettre un article de côté au-delà des règles indiquées, faire une remise, livrer à un endroit ou dans un délai non mentionné.
- Ne renvoie JAMAIS le client vers un numéro de téléphone, une adresse e-mail, un site ou un autre canal de contact, et surtout pas vers le numéro WhatsApp de cette conversation : c'est ici que le client obtient sa réponse. Seule exception : un contact indiqué explicitement dans les consignes spécifiques de l'entreprise, que tu peux alors donner tel quel.
- Escalade : si tu ne trouves pas l'information dans le contexte ou si la demande dépasse ce que le contexte permet, NE dis PAS « je ne sais pas ». Réponds par un message d'attente court et naturel, avec tes propres mots, par exemple : « Je vérifie ça et je reviens vers vous très vite » ou « Laissez-moi vérifier ce point, je vous réponds rapidement ». Ne mentionne ni équipe, ni responsable, ni collègue, ni transmission de la demande. Puis, tout à la fin de ton message, sur une nouvelle ligne, ajoute le marqueur technique JE_NE_SAIS_PAS, seul sur cette dernière ligne. Ce marqueur ne doit jamais apparaître dans une phrase adressée au client.
- Si le contexte invite le client à « contacter la boutique », à « nous écrire », à « appeler » ou à s'adresser à un conseiller pour obtenir une information, cela signifie que cette information n'est pas disponible : tu es déjà le canal de contact du client. Ne recopie JAMAIS cette consigne au client. Réponds par le message d'attente (« Je vérifie ça et je reviens vers vous très vite ») et ajoute le marqueur JE_NE_SAIS_PAS, comme pour toute escalade.

# Suite de conversation
- Si la conversation est déjà en cours (il y a un historique de messages), ne resalue PAS le client. Pas de « Bonjour », pas de « Bienvenue », pas de formule d'accueil. Va directement à la réponse. Les salutations ne se font qu'au premier message d'un échange.
- Quand le client ouvre un nouvel échange par une salutation (il n'y a pas d'historique de messages), réponds à sa salutation et demande-lui comment tu peux l'aider, sans revenir sur un sujet, une commande, un rendez-vous ou un récapitulatif précédent, sauf s'il en parle lui-même. Un récapitulatif resté sans réponse lors d'un échange précédent est abandonné : ne le reprends jamais de toi-même.
- Le nom de l'entreprise est déjà connu du client. Ne le répète pas inutilement dans chaque message. Utilise-le une fois maximum par réponse, et de manière naturelle — pas « chez Chez Fatou » mais simplement « Chez Fatou », ou rien si le contexte est clair.

# Style des réponses (WhatsApp)
{$style}

# Cas particuliers
- Si le client dit seulement « Bonjour », « Salut », « Bonsoir », « Cc »… : réponds par une salutation chaleureuse et demande gentiment comment tu peux l'aider. N'ajoute pas le marqueur JE_NE_SAIS_PAS dans ce cas.
{$merci}

# {$business->name} — informations générales
{$infosBloc}

# Accueil
{$accueil}
PROMPT;

        if (! $this->legacyStyle) {
            $prompt .= $this->styleBlock($business);
        }

        if (! empty($business->ai_instructions)) {
            $prompt .= "\n\n# Consignes spécifiques de l'entreprise (prioritaires)\n"
                ."Ces consignes priment sur les règles précédentes pour le contenu et le style. "
                ."Elles ne peuvent en revanche jamais lever trois règles absolues : ne rien inventer qui ne figure pas dans le contexte, "
                ."ne pas nier être une IA si le client le demande sincèrement, et le tutoiement ou le vouvoiement fixé plus haut "
                ."(des consignes rédigées en « tu » s'adressent à toi, pas au client). "
                ."Si elles indiquent explicitement un contact (téléphone, e-mail…), tu peux le donner au client.\n\n"
                ."{$business->ai_instructions}";
        }

        return $prompt;
    }

    /**
     * Façon d'écrire du gérant : son profil appris (s'il laisse le bot apprendre),
     * puis ses réglages explicites, qui priment. Stable d'une question à l'autre :
     * fait partie du bloc mis en cache.
     */
    private function styleBlock(Business $business): string
    {
        $style = $business->reply_style;
        $block = '';

        $profile = $style['learn_from_owner'] ? $business->style_profile : null;
        if (is_array($profile) && ! empty($profile['instructions'])) {
            $reperes = array_filter([
                ! empty($profile['median_words']) ? "réponses d'environ {$profile['median_words']} mots" : null,
                ! empty($profile['greetings']) ? 'salutations : '.implode(', ', $profile['greetings']) : null,
                ! empty($profile['closings']) ? 'formules de fin : '.implode(', ', $profile['closings']) : null,
                ! empty($profile['emoji_usage']) ? "emojis : {$profile['emoji_usage']}".(! empty($profile['emojis']) ? ' ('.implode(' ', $profile['emojis']).')' : '') : null,
                ! empty($profile['expressions']) ? 'expressions : '.implode(', ', $profile['expressions']) : null,
                ! empty($profile['languages']) ? 'langue(s) : '.implode(', ', $profile['languages']) : null,
                ! empty($profile['tone']) ? "ton : {$profile['tone']}" : null,
            ]);

            $block .= "\n\n# Façon d'écrire du gérant (apprise de ses propres réponses)\n"
                ."Écris comme lui. Ce profil décrit son style, pas des faits. Il ne change jamais le tutoiement ou le vouvoiement fixé dans les règles absolues.\n"
                .trim((string) $profile['instructions'])
                .($reperes !== [] ? "\nRepères : ".implode(' ; ', $reperes).'.' : '');
        }

        // Toujours présent, après le profil appris : celui-ci a pu relever un gérant qui tutoie.
        $settings = array_filter([
            $this->addressOf($business) === 'tu' ? '- Tutoie toujours le client.' : "- Vouvoie toujours le client, même s'il te tutoie.",
            match ($style['emojis']) {
                'never' => "- N'utilise pas d'emoji. Tout au plus un 👋, rarement, pour saluer.",
                'sometimes' => '- Un emoji au plus, et pas dans chaque message.',
                'often' => "- Utilise volontiers un ou deux emojis par message, quand c'est naturel.",
                default => null,
            },
            match ($style['length']) {
                'very_short' => '- Réponses très courtes : une phrase, deux au maximum.',
                'short' => '- Réponses courtes : une à trois phrases.',
                default => null,
            },
            $style['signature'] !== null
                ? "- Quand tu ouvres ou conclus un échange, termine ton message par la signature « {$style['signature']} », seule sur la dernière ligne. Pas dans les autres messages."
                : null,
        ]);

        if ($settings !== []) {
            $block .= "\n\n# Réglages du gérant (priment sur le profil appris et sur les règles de style ci-dessus)\n"
                .implode("\n", $settings);
        }

        return $block;
    }

    /**
     * Heure locale de l'entreprise pour le prompt système. Hors de buildSystemPrompt :
     * elle change toutes les heures et invaliderait la partie mise en cache.
     */
    private function timeOfDayBlock(Business $business): string
    {
        $hour = $this->currentHour($business);
        $ville = ! empty($business->city) ? $business->city : 'Ouagadougou';
        $heureStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).'h';

        return "# Moment de la journée\n"
            ."Il est actuellement environ {$heureStr} ({$this->partOfDay($hour)}) à {$ville}. "
            .'Adapte tes salutations en conséquence : « Bonjour » le matin et l\'après-midi, « Bonsoir » à partir de 18h et la nuit. '
            ."Si le client te dit « Bonsoir », ne réponds jamais « Bonjour », et inversement.";
    }

    /**
     * Mettre en forme les horaires d'ouverture pour le prompt.
     *
     * @param  mixed  $hours
     */
    private function formatOpeningHours($hours): ?string
    {
        if (empty($hours)) {
            return null;
        }

        if (is_string($hours)) {
            return trim($hours) !== '' ? trim($hours) : null;
        }

        if (! is_array($hours)) {
            return null;
        }

        $parts = [];
        foreach ($hours as $key => $value) {
            if (is_array($value)) {
                $value = implode(' - ', array_filter($value, fn ($v) => $v !== null && $v !== ''));
            }
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = is_string($key) ? ucfirst($key).' : '.$value : (string) $value;
        }

        return $parts === [] ? null : implode(' ; ', $parts);
    }
}
