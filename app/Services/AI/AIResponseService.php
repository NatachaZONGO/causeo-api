<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Services\Embedding\EmbeddingService;
use App\Services\OrderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AIResponseService
{
    private string $model;

    private const SIMILARITY_THRESHOLD = 0.5;

    /** Nombre maximal d'allers-retours tool_use / tool_result par réponse. */
    private const MAX_TOOL_ROUNDS = 3;

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
    ) {
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * Générer une réponse à la question d'un client pour une entreprise donnée.
     *
     * @return array{answer: ?string, confidence: float, should_escalate: bool, context_used: array<int, mixed>, media_ids: array<int, string>}
     */
    public function answer(Business $business, string $question, ?string $conversationId = null): array
    {
        $conversation = $conversationId !== null ? Conversation::find($conversationId) : null;

        $simple = $this->handleSimpleMessage($business, $question, $this->hasRecentBotReply($conversationId));
        if ($simple !== null) {
            return $simple;
        }

        $context = collect();
        $trace = ['orders' => [], 'tool_errors' => [], 'blocked_claim' => false];

        try {
            $context = $this->findRelevantContext($business, $question);
            $hasContext = $context->isNotEmpty();

            $media = $this->findRelevantMedia($business, $question);

            $messages = $this->buildConversationHistory($conversationId, $question);

            $systemPrompt = $this->buildSystemPrompt($business);

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

            if ($hasContext) {
                $contextText = $context
                    ->map(fn ($item) => '- '.$item->content)
                    ->implode("\n");

                $userMessage = "Contexte :\n{$contextText}\n\nQuestion du client : {$question}";
            } else {
                $systemPrompt .= "\n\n# Aucune information trouvée dans la base documentaire\n"
                    ."Aucune information spécifique n'a été trouvée dans la base documentaire. "
                    ."Si le client fait de la conversation simple (salutation, remerciement, question générale sur l'entreprise), "
                    ."réponds naturellement et chaleureusement. "
                    ."Les informations déjà données dans l'historique de cette conversation (par exemple un récapitulatif de commande) restent valables. "
                    ."Si le client pose une question technique ou spécifique sur un produit / service / prix / horaire "
                    ."que tu ne connais pas, ne confirme rien et n'infirme rien : réponds par un message d'attente court, "
                    ."par exemple « Je vérifie ça et je reviens vers vous très vite 😊 », sans mentionner d'équipe, "
                    ."de responsable ni de transmission, puis ajoute JE_NE_SAIS_PAS seul sur la dernière ligne.";

                $userMessage = $question;
            }

            if ($media->isNotEmpty()) {
                $mediaList = $media
                    ->map(fn ($m) => "{$m->title} (id: {$m->id}, type: {$m->type})")
                    ->implode(', ');
                $userMessage .= "\n\nMédias disponibles à envoyer au client : {$mediaList}";
            }

            if ($messages !== [] && end($messages)['role'] === 'user') {
                $messages[count($messages) - 1]['content'] .= "\n\n".$userMessage;
            } else {
                $messages[] = ['role' => 'user', 'content' => $userMessage];
            }

            // La prise de commande n'est possible que dans une vraie conversation client,
            // et seulement si la boutique propose au moins un mode de remise.
            $tools = [];
            if ($conversation !== null && $business->enabledFulfillmentTypes() !== []) {
                $systemPrompt .= "\n\n".$this->orderInstructions($business);
                $tools = [$this->createOrderTool($business)];
            }

            $request = [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $messages,
            ];
            if ($tools !== []) {
                $request['tools'] = $tools;
            }

            $payload = $this->converse($request, $business, $conversation, $trace);
            $text = $this->extractText($payload);

            // Une réponse qui annonce une commande enregistrée (ou cite une référence)
            // sans appel réussi à create_order n'est jamais envoyée : un seul nouvel
            // essai avec une consigne de correction, puis escalade.
            $violation = $this->unverifiedOrderClaim($text, $trace, $conversation);

            if ($violation !== null) {
                Log::warning('AIResponseService: confirmation de commande non vérifiée bloquée', [
                    'conversation_id' => $conversationId,
                    'reason' => $violation,
                    'text' => $text,
                ]);

                $request['messages'][] = ['role' => 'assistant', 'content' => $payload['content']];
                $request['messages'][] = ['role' => 'user', 'content' => $this->orderClaimCorrection($violation)];

                $payload = $this->converse($request, $business, $conversation, $trace);
                $text = $this->extractText($payload);
                $violation = $this->unverifiedOrderClaim($text, $trace, $conversation);

                if ($violation !== null) {
                    Log::warning('AIResponseService: confirmation de commande non vérifiée bloquée deux fois, escalade', [
                        'conversation_id' => $conversationId,
                        'reason' => $violation,
                        'text' => $text,
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

            // Extrait puis retire la ligne technique MEDIA:id1,id2 de la réponse.
            $mediaIds = [];
            if ($media->isNotEmpty() && preg_match('/^\s*MEDIA\s*:\s*(.+)$/mi', $text, $matches)) {
                $requested = array_filter(array_map('trim', explode(',', $matches[1])));
                $validIds = $media->pluck('id')->all();
                $mediaIds = array_values(array_intersect($requested, $validIds));

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
     * @param  array{orders: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool}  $trace
     * @return array<string, mixed>
     */
    private function converse(array &$request, Business $business, ?Conversation $conversation, array &$trace): array
    {
        $payload = $this->callClaude($request);

        for ($round = 0; ($payload['stop_reason'] ?? null) === 'tool_use' && $round < self::MAX_TOOL_ROUNDS; $round++) {
            $request['messages'][] = ['role' => 'assistant', 'content' => $payload['content']];
            $request['messages'][] = [
                'role' => 'user',
                'content' => $this->runTools($payload['content'], $business, $conversation, $trace),
            ];

            $payload = $this->callClaude($request);
        }

        return $payload;
    }

    /**
     * Vérifier qu'une réponse n'annonce pas une commande qui n'existe pas.
     * Renvoie la raison du blocage, ou null si la réponse peut être envoyée.
     *
     * @param  array{orders: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool}  $trace
     */
    private function unverifiedOrderClaim(string $text, array $trace, ?Conversation $conversation): ?string
    {
        $cited = $this->citedOrderReferences($text);
        $claims = $this->claimsOrderRecorded($text);

        if ($cited === [] && ! $claims) {
            return null;
        }

        $known = array_merge(
            array_column($trace['orders'], 'reference'),
            $this->conversationOrderReferences($conversation),
        );

        $unknown = array_values(array_diff($cited, $known));
        if ($unknown !== []) {
            return 'référence sans commande correspondante : '.implode(', ', $unknown);
        }

        if ($claims && $trace['orders'] === [] && $cited === []) {
            return 'commande annoncée comme enregistrée sans appel réussi à create_order';
        }

        return null;
    }

    /**
     * Références de commande citées dans un texte (« Référence : 871086 », « réf. 3F2A9C »…).
     *
     * @return array<int, string>
     */
    private function citedOrderReferences(string $text): array
    {
        // Soit un identifiant après « : », « # » ou « n° », soit 6 caractères hexadécimaux
        // (format des références réelles, qui peuvent ne contenir que des lettres).
        preg_match_all(
            '/\br[ée]f(?:[ée]rence)?\.?\s*(?:de\s+(?:la\s+|votre\s+)?commande\s*)?'
            .'(?:(?:n[°o]\.?|[:#])\s*[*_]*\s*([a-z0-9][a-z0-9-]{2,15})|[*_]*\s*([0-9a-f]{6}))\b/iu',
            $text,
            $matches,
        );

        $references = array_filter(array_merge($matches[1], $matches[2]));

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

        preg_match_all(
            '/\bcommandes?\b[^.!?\n]{0,60}?\b(?:enregistr[ée]+e?s?|confirm[ée]+e?s?|valid[ée]+e?s?|prise en compte)\b/iu',
            $text,
            $matches,
        );

        foreach ($matches[0] as $match) {
            // « sera enregistrée après confirmation » ou « n'a pas été enregistrée » ne sont pas des annonces.
            if (preg_match('/\b(?:sera|seront|serait|pourra|dès que|une fois|après|avant|si|pas|aucune?|jamais|n[\'’](?:a|est|ont))\b/iu', $match) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Références des commandes déjà enregistrées pour cette conversation.
     *
     * @return array<int, string>
     */
    private function conversationOrderReferences(?Conversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        return Order::query()
            ->where('conversation_id', $conversation->id)
            ->pluck('id')
            ->map(fn (string $id) => strtoupper(substr($id, -6)))
            ->all();
    }

    /**
     * Note interne ajoutée à un message passé du bot qui parle d'une commande, pour
     * que le modèle sache si elle a réellement été enregistrée et n'imite pas une
     * confirmation passée.
     *
     * @param  array<int, string>  $knownReferences
     */
    private function orderNote(string $content, ?string $metadata, array $knownReferences): string
    {
        $recorded = array_column((array) data_get(json_decode((string) $metadata, true), 'orders', []), 'reference');
        $cited = $this->citedOrderReferences($content);

        if ($recorded === [] && $cited === [] && ! $this->claimsOrderRecorded($content)) {
            return '';
        }

        $valid = array_values(array_unique(array_merge($recorded, array_intersect($cited, $knownReferences))));
        $invalid = array_values(array_diff($cited, $knownReferences, $recorded));

        if ($valid !== [] && $invalid === []) {
            return "\n[Note système : commande ".implode(', ', $valid).' réellement enregistrée par l\'outil create_order.]';
        }

        return "\n[Note système : aucune commande n'a été enregistrée pour ce message"
            .($invalid !== [] ? ' ; la référence '.implode(', ', $invalid).' n\'existe pas' : '')
            .'. Ne t\'en sers pas comme modèle.]';
    }

    /**
     * Consigne de correction envoyée à Claude quand sa réponse a été bloquée.
     */
    private function orderClaimCorrection(string $reason): string
    {
        return "[Message système, pas du client : ne le mentionne pas dans ta réponse] Ta réponse précédente n'a pas été envoyée ({$reason}). "
            ."Aucune commande n'a été enregistrée par l'outil create_order dans cet échange. "
            ."Si le client a clairement confirmé le récapitulatif complet, appelle maintenant create_order. "
            ."Sinon, réécris ta réponse au client sans dire que la commande est enregistrée et sans citer de référence. "
            ."Une référence ne vient que du résultat de l'outil.";
    }

    /**
     * Trace des appels d'outils à enregistrer dans le metadata du message sortant.
     *
     * @param  array{orders: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool}  $trace
     * @return array<string, mixed>
     */
    private function traceForMetadata(array $trace): array
    {
        return array_filter([
            'orders' => $trace['orders'],
            'tool_errors' => $trace['tool_errors'],
            'blocked_claim' => $trace['blocked_claim'],
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
     * @param  array{orders: array<int, array{id: string, reference: string}>, tool_errors: array<int, string>, blocked_claim: bool}  $trace
     * @return array<int, array<string, mixed>>
     */
    private function runTools(array $content, Business $business, ?Conversation $conversation, array &$trace): array
    {
        $results = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $result = ['type' => 'tool_result', 'tool_use_id' => $block['id']];

            if ($block['name'] !== 'create_order' || $conversation === null) {
                $trace['tool_errors'][] = "Outil inconnu : {$block['name']}.";
                $results[] = $result + ['content' => "Outil inconnu : {$block['name']}.", 'is_error' => true];

                continue;
            }

            $input = (array) ($block['input'] ?? []);

            try {
                $order = $this->orderService->createFromAi($business, $conversation, $input);

                $trace['orders'][] = ['id' => $order->id, 'reference' => $order->reference()];

                Log::info('AIResponseService: create_order a enregistré une commande', [
                    'conversation_id' => $conversation->id,
                    'order_id' => $order->id,
                    'reference' => $order->reference(),
                    'input' => $input,
                ]);

                $results[] = $result + ['content' => json_encode([
                    'status' => 'commande enregistrée',
                    'reference' => $order->reference(),
                    'total_amount' => $order->total_amount,
                    'currency' => 'FCFA',
                    'items' => $order->items,
                ], JSON_UNESCAPED_UNICODE)];
            } catch (InvalidArgumentException $e) {
                $trace['tool_errors'][] = $e->getMessage();

                Log::info('AIResponseService: create_order refusé', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    'input' => $input,
                ]);

                $results[] = $result + ['content' => $e->getMessage(), 'is_error' => true];
            }
        }

        return $results;
    }

    /**
     * Définition de l'outil create_order, limitée aux modes de remise activés.
     *
     * @return array<string, mixed>
     */
    private function createOrderTool(Business $business): array
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
                            ],
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
    private function orderInstructions(Business $business): string
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
1. Collecte les informations manquantes, sans redemander ce que le client a déjà donné : les articles (nom, options comme la taille ou la couleur si elles existent, quantité), le nom du client, le mode de remise et le mode de paiement. Si le client n'a pas dit comment il souhaite recevoir sa commande, demande-le-lui en citant uniquement les modes proposés ci-dessus. Ne propose que des articles, options, villes et modes de paiement présents dans le contexte ; sinon, applique la règle d'escalade.
2. Selon le mode de remise : pour une livraison, demande la ville et l'adresse ; pour une expédition, demande la ville de destination ; pour un retrait en boutique, ne demande pas d'adresse, mais donne au client l'adresse et les horaires de la boutique figurant dans les informations générales (ne les invente pas s'ils n'y figurent pas).
3. Utilise uniquement les prix du contexte, ou ceux d'un récapitulatif déjà fait dans cette conversation. N'invente JAMAIS un prix absent : dans ce cas, n'enregistre pas la commande et applique la règle d'escalade. Si le contexte indique des frais de livraison ou d'expédition pour la ville du client, ajoute-les comme une ligne « Frais de livraison » (quantité 1). Ne facture JAMAIS de frais de livraison pour un retrait en boutique.
4. Fais un récapitulatif clair : chaque article avec sa quantité et son prix unitaire, le total (somme des quantités × prix unitaires), le mode de remise et le paiement. Termine en demandant une confirmation explicite, par exemple « Je confirme la commande ? ».
5. N'appelle create_order qu'après un « oui » clair du client à ce récapitulatif. Si le client modifie quelque chose, refais le récapitulatif et redemande confirmation. N'appelle jamais l'outil deux fois pour la même commande.
6. Une fois l'outil exécuté, confirme la commande au client : donne la référence et le total renvoyés par l'outil, puis un court récapitulatif (articles et quantités, mode de remise, paiement). Termine par une phrase neutre, par exemple « Nous revenons vers vous très vite pour finaliser la livraison et le paiement 😊 » (ou « le retrait et le paiement » pour un retrait). Ne décris JAMAIS une procédure qui ne figure pas dans le contexte : pas d'appel d'un conseiller, pas d'heure ou de jour de livraison, pas de modalités de paiement (numéro, lien, moment du paiement) que le contexte n'indique pas.
7. N'annonce jamais une commande comme enregistrée si l'outil ne l'a pas confirmé dans ce même échange ; s'il renvoie une erreur, demande au client l'information manquante.
8. La référence d'une commande vient uniquement du résultat de l'outil create_order. N'invente jamais de référence et ne réutilise jamais celle d'une commande précédente pour une nouvelle demande : chaque nouvelle commande passe par un nouveau récapitulatif, une nouvelle confirmation et un nouvel appel à l'outil.
9. Si un récapitulatif attend encore une réponse du client et qu'il demande autre chose (un autre article, une autre ville, un autre mode de remise…), demande-lui d'abord s'il souhaite ajouter cela à sa commande en cours ou remplacer sa commande, puis refais le récapitulatif en conséquence.
10. Les notes « [Note système …] » de l'historique sont internes : elles indiquent si une commande a réellement été enregistrée. Ne les recopie jamais au client.
PROMPT;
    }

    /**
     * Indiquer si le bot a déjà répondu dans cette conversation au cours des dernières 24 h.
     */
    private function hasRecentBotReply(?string $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        return DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    /**
     * Récupérer le contexte pertinent (chunks de documents + réponses apprises).
     *
     * @return Collection<int, object>
     */
    public function findRelevantContext(Business $business, string $question, int $limit = 5): Collection
    {
        $vector = '['.implode(',', $this->embeddingService->embed($question)).']';

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
                $vector = '['.implode(',', $this->embeddingService->embed($question)).']';

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
     * Construire l'historique de conversation au format messages Claude.
     *
     * Prend au plus les 10 derniers messages des dernières 24 h, ordonnés du
     * plus ancien au plus récent, en excluant le message courant qui vient
     * d'être enregistré.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(?string $conversationId, string $currentQuestion): array
    {
        if ($conversationId === null) {
            return [];
        }

        $rows = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['direction', 'content', 'metadata', 'created_at'])
            ->reverse()
            ->values();

        $knownReferences = $this->conversationOrderReferences(Conversation::find($conversationId));

        // Le message entrant courant est déjà en base : on le retire s'il est en dernier.
        if ($rows->isNotEmpty()) {
            $last = $rows->last();
            if ($last->direction === 'inbound' && trim((string) $last->content) === trim($currentQuestion)) {
                $rows = $rows->slice(0, -1)->values();
            }
        }

        $messages = [];
        foreach ($rows as $row) {
            $content = trim((string) $row->content);
            if ($content === '') {
                continue;
            }

            $role = $row->direction === 'inbound' ? 'user' : 'assistant';

            if ($role === 'assistant') {
                $content .= $this->orderNote($content, $row->metadata, $knownReferences);
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
    private function handleSimpleMessage(Business $business, string $question, bool $inConversation = false): ?array
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

        if ($isGreeting) {
            $name = $business->name;
            $hello = $this->resolveGreetingWord($business, $normalized);
            $moment = $hello === 'Bonsoir' ? 'ce soir' : "aujourd'hui";

            $variants = ! empty($business->custom_greeting)
                ? [
                    trim($business->custom_greeting),
                    "{$hello} et bienvenue chez *{$name}* 👋 Comment puis-je vous aider {$moment} ?",
                    "{$hello} ! Ravi de vous accueillir chez *{$name}*. Que puis-je faire pour vous ?",
                ]
                : [
                    "{$hello} et bienvenue chez *{$name}* 👋 Comment puis-je vous aider {$moment} ?",
                    "{$hello} ! Ici l'équipe de *{$name}*. Que puis-je faire pour vous ?",
                    "{$hello}, merci de nous écrire chez *{$name}*. Comment puis-je vous aider ?",
                ];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        if ($isThanks) {
            $variants = [
                'Avec plaisir ! Puis-je vous aider sur autre chose ?',
                "Je vous en prie 🙏 N'hésitez pas si vous avez besoin d'autre chose.",
                'De rien ! Y a-t-il autre chose que je peux faire pour vous ?',
            ];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        if ($isGoodbye) {
            $name = $business->name;
            $variants = [
                "Merci de nous avoir contactés, à très bientôt chez *{$name}* ! 👋",
                'Passez une excellente journée, et à bientôt !',
                "Au revoir et merci pour votre confiance. À bientôt chez *{$name}* !",
            ];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        // Après une réponse du bot, un « oui » / « ok » répond à sa question
        // (par exemple la confirmation d'une commande) : on laisse l'IA le traiter.
        if ($isAffirmation && ! $inConversation) {
            $variants = [
                'Très bien ! Comment puis-je vous aider davantage ?',
                "Parfait 👍 Que puis-je faire d'autre pour vous ?",
                "D'accord ! Avez-vous besoin d'autre chose ?",
            ];

            return $this->simpleResponse($this->pickRandom($variants));
        }

        return null;
    }

    /**
     * Fuseau horaire de l'entreprise (défaut : Africa/Ouagadougou, GMT+0).
     */
    private function businessTimezone(Business $business): string
    {
        $tz = is_string($business->timezone ?? null) ? trim($business->timezone) : '';

        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return 'Africa/Ouagadougou';
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

        $hour = $this->currentHour($business);
        $ville = ! empty($business->city) ? $business->city : 'Ouagadougou';
        $heureStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).'h';
        $heureBloc = "Il est actuellement environ {$heureStr} ({$this->partOfDay($hour)}) à {$ville}. "
            .'Adapte tes salutations en conséquence : « Bonjour » le matin et l\'après-midi, « Bonsoir » à partir de 18h et la nuit. '
            ."Si le client te dit « Bonsoir », ne réponds jamais « Bonjour », et inversement.";

        $prompt = <<<PROMPT
{$identite}

Tu discutes avec un client sur WhatsApp. Tu réponds au nom de {$business->name}, comme un membre de son service client qui connaît bien la maison, au Burkina Faso.

# Ta personnalité
- Chaleureux, accueillant, poli. Tu parles comme un bon agent d'accueil burkinabè : naturel, respectueux, jamais froid ni mécanique.
- Ton amical mais professionnel. Tu peux utiliser 1 à 2 émojis maximum par message, jamais plus, et seulement quand c'est naturel.
- Tu adaptes ton niveau de langue à celui du client : s'il est très informel, tu te détends un peu ; s'il est formel, tu restes courtois et posé.

# Règles absolues
- Réponds TOUJOURS en français, de façon fluide et humaine.
- Ne te présente pas de toi-même comme une intelligence artificielle, un robot ou un programme. Mais si le client demande sincèrement s'il parle à une IA ou à un robot, ne le nie JAMAIS : reconnais-le simplement et avec naturel, puis propose ton aide.
- N'invente JAMAIS d'information : prix, stock, disponibilité, horaires, produits, services, délais, villes desservies, conditions, promotions. Utilise uniquement ce qui est écrit dans le contexte fourni plus bas dans la conversation.
- Si une information manque dans le contexte, ne la confirme pas et ne l'infirme pas non plus : ne dis ni « oui » ni « non » (par exemple, si une ville n'est pas mentionnée, ne dis pas que vous y livrez, ni que vous n'y livrez pas). Applique alors la règle d'escalade ci-dessous.
- Ne promets JAMAIS un service qui ne figure pas dans le contexte : par exemple prévenir le client d'un retour en stock, mettre un article de côté au-delà des règles indiquées, faire une remise, livrer à un endroit ou dans un délai non mentionné.
- Ne renvoie JAMAIS le client vers un numéro de téléphone, une adresse e-mail, un site ou un autre canal de contact, et surtout pas vers le numéro WhatsApp de cette conversation : c'est ici que le client obtient sa réponse. Seule exception : un contact indiqué explicitement dans les consignes spécifiques de l'entreprise, que tu peux alors donner tel quel.
- Escalade : si tu ne trouves pas l'information dans le contexte ou si la demande dépasse ce que le contexte permet, NE dis PAS « je ne sais pas ». Réponds par un message d'attente court et naturel, avec tes propres mots, par exemple : « Je vérifie ça et je reviens vers vous très vite 😊 » ou « Laissez-moi vérifier ce point, je vous réponds rapidement ». Ne mentionne ni équipe, ni responsable, ni collègue, ni transmission de la demande. Puis, tout à la fin de ton message, sur une nouvelle ligne, ajoute le marqueur technique JE_NE_SAIS_PAS, seul sur cette dernière ligne. Ce marqueur ne doit jamais apparaître dans une phrase adressée au client.
- Si le contexte invite le client à « contacter la boutique », à « nous écrire », à « appeler » ou à s'adresser à un conseiller pour obtenir une information, cela signifie que cette information n'est pas disponible : tu es déjà le canal de contact du client. Ne recopie JAMAIS cette consigne au client. Réponds par le message d'attente (« Je vérifie ça et je reviens vers vous très vite 😊 ») et ajoute le marqueur JE_NE_SAIS_PAS, comme pour toute escalade.

# Suite de conversation
- Si la conversation est déjà en cours (il y a un historique de messages), ne resalue PAS le client. Pas de « Bonjour », pas de « Bienvenue », pas de formule d'accueil. Va directement à la réponse. Les salutations ne se font qu'au tout premier message de la conversation.
- Le nom de l'entreprise est déjà connu du client. Ne le répète pas inutilement dans chaque message. Utilise-le une fois maximum par réponse, et de manière naturelle — pas « chez Chez Fatou » mais simplement « Chez Fatou », ou rien si le contexte est clair.

# Style des réponses (WhatsApp)
- Sois concis : 3 à 4 courts paragraphes maximum. Les clients WhatsApp veulent des réponses rapides et claires.
- Mets en forme pour WhatsApp : *gras* pour les titres et les éléments importants (prix, noms de produits, points clés), des sauts de ligne pour aérer. Pour une liste, va à la ligne pour chaque élément.
- Si le client pose plusieurs questions à la fois, réponds à toutes, de manière organisée (une partie par question si besoin).
- Termine souvent par une question de relance neutre, qui ne propose aucun service : « Avez-vous une préférence particulière ? », « Puis-je vous aider pour autre chose ? »

# Cas particuliers
- Si le client dit seulement « Bonjour », « Salut », « Bonsoir », « Cc »… : réponds par une salutation chaleureuse et demande gentiment comment tu peux l'aider. N'ajoute pas le marqueur JE_NE_SAIS_PAS dans ce cas.
- Si le client dit « Merci », « Ok merci », « C'est noté »… : réponds poliment (« Avec plaisir ! »), et propose ton aide pour autre chose. N'ajoute pas le marqueur JE_NE_SAIS_PAS dans ce cas.

# {$business->name} — informations générales
{$infosBloc}

# Accueil
{$accueil}

# Moment de la journée
{$heureBloc}
PROMPT;

        if (! empty($business->ai_instructions)) {
            $prompt .= "\n\n# Consignes spécifiques de l'entreprise (prioritaires)\n"
                ."Ces consignes priment sur les règles précédentes pour le contenu et le style. "
                ."Elles ne peuvent en revanche jamais lever deux règles absolues : ne rien inventer qui ne figure pas dans le contexte, "
                ."et ne pas nier être une IA si le client le demande sincèrement. "
                ."Si elles indiquent explicitement un contact (téléphone, e-mail…), tu peux le donner au client.\n\n"
                ."{$business->ai_instructions}";
        }

        return $prompt;
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
