<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Services\Embedding\EmbeddingService;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIResponseService
{
    private Client $client;

    private string $model;

    private const SIMILARITY_THRESHOLD = 0.5;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers' => [
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'verify' => config('services.curl_ca_bundle', true),
        ]);

        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * Générer une réponse à la question d'un client pour une entreprise donnée.
     *
     * @return array{answer: ?string, confidence: float, should_escalate: bool, context_used: array<int, mixed>}
     */
    public function answer(Business $business, string $question, ?string $conversationId = null): array
    {
        $simple = $this->handleSimpleMessage($business, $question);
        if ($simple !== null) {
            return $simple;
        }

        $context = collect();

        try {
            $context = $this->findRelevantContext($business, $question);
            $hasContext = $context->isNotEmpty();

            $messages = $this->buildConversationHistory($conversationId, $question);

            $systemPrompt = $this->buildSystemPrompt($business);

            if ($messages !== []) {
                $systemPrompt .= "\n\n# Contexte de la conversation\n"
                    ."Note : cette conversation est déjà en cours, le client a déjà été accueilli. "
                    .'Réponds directement à sa question sans resaluer.';
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
                    ."Si le client pose une question technique ou spécifique sur un produit / service / prix / horaire "
                    ."que tu ne connais pas, réponds naturellement que tu vas te renseigner puis ajoute JE_NE_SAIS_PAS "
                    .'sur la dernière ligne.';

                $userMessage = $question;
            }

            if ($messages !== [] && end($messages)['role'] === 'user') {
                $messages[count($messages) - 1]['content'] .= "\n\n".$userMessage;
            } else {
                $messages[] = ['role' => 'user', 'content' => $userMessage];
            }

            $response = $this->client->post('messages', [
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => 1024,
                    'system' => $systemPrompt,
                    'messages' => $messages,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);

            $text = trim($payload['content'][0]['text'] ?? '');

            if ($text === '') {
                return [
                    'answer' => null,
                    'confidence' => 0,
                    'should_escalate' => true,
                    'context_used' => $context->pluck('id')->all(),
                ];
            }

            if (str_contains($text, 'JE_NE_SAIS_PAS')) {
                $visible = trim(str_replace('JE_NE_SAIS_PAS', '', $text));

                return [
                    'answer' => $visible !== '' ? $visible : null,
                    'confidence' => 0.2,
                    'should_escalate' => true,
                    'context_used' => $context->pluck('id')->all(),
                ];
            }

            return [
                'answer' => $text,
                'confidence' => $hasContext ? $this->averageSimilarity($context) : 0.3,
                'should_escalate' => false,
                'context_used' => $context->pluck('id')->all(),
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
            ];
        }
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
            ->get(['direction', 'content', 'created_at'])
            ->reverse()
            ->values();

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
    private function handleSimpleMessage(Business $business, string $question): ?array
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

        if ($isAffirmation) {
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

        $identite = "Tu fais partie de l'équipe de *{$business->name}*";
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
        if (! empty($business->phone)) {
            $infos[] = "Téléphone : {$business->phone}";
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

Tu discutes avec un client sur WhatsApp. Tu incarnes une vraie personne du service client, quelqu'un de {$business->name} qui connaît bien la maison, au Burkina Faso.

# Ta personnalité
- Chaleureux, accueillant, poli. Tu parles comme un bon agent d'accueil burkinabè : naturel, respectueux, jamais froid ni mécanique.
- Ton amical mais professionnel. Tu peux utiliser 1 à 2 émojis maximum par message, jamais plus, et seulement quand c'est naturel.
- Tu adaptes ton niveau de langue à celui du client : s'il est très informel, tu te détends un peu ; s'il est formel, tu restes courtois et posé.

# Règles absolues
- Réponds TOUJOURS en français, de façon fluide et humaine.
- Ne dis JAMAIS que tu es une intelligence artificielle, un robot, un bot, un assistant virtuel ou un programme. Tu es un membre de l'équipe de {$business->name}. Si on te pose la question, reste évasif et ramène poliment la conversation vers la façon dont tu peux aider.
- N'invente JAMAIS d'information : prix, horaires, produits, services, délais, promotions. Utilise uniquement ce qui est écrit dans le contexte fourni plus bas dans la conversation.
- Si tu ne trouves pas l'information dans le contexte, NE dis PAS « je ne sais pas ». Réponds naturellement en disant que tu vas te renseigner, avec tes propres mots. Par exemple : « Ah bonne question ! Je n'ai pas cette info sous la main, laissez-moi vérifier avec le responsable. On vous revient très vite ! » ou « Je vais me renseigner sur ce point et vous donner la réponse rapidement 😊 ». Puis, tout à la fin de ton message, sur une nouvelle ligne, ajoute le marqueur technique JE_NE_SAIS_PAS, seul sur cette dernière ligne. Ce marqueur ne doit jamais apparaître dans une phrase adressée au client.

# Suite de conversation
- Si la conversation est déjà en cours (il y a un historique de messages), ne resalue PAS le client. Pas de « Bonjour », pas de « Bienvenue », pas de formule d'accueil. Va directement à la réponse. Les salutations ne se font qu'au tout premier message de la conversation.
- Le nom de l'entreprise est déjà connu du client. Ne le répète pas inutilement dans chaque message. Utilise-le une fois maximum par réponse, et de manière naturelle — pas « chez Chez Fatou » mais simplement « Chez Fatou », ou rien si le contexte est clair.

# Style des réponses (WhatsApp)
- Sois concis : 3 à 4 courts paragraphes maximum. Les clients WhatsApp veulent des réponses rapides et claires.
- Mets en forme pour WhatsApp : *gras* pour les titres et les éléments importants (prix, noms de produits, points clés), des sauts de ligne pour aérer. Pour une liste, va à la ligne pour chaque élément.
- Si le client pose plusieurs questions à la fois, réponds à toutes, de manière organisée (une partie par question si besoin).
- Termine souvent par une question ouverte ou une proposition d'aide : « Souhaitez-vous que je vous réserve une table ? », « Puis-je vous aider sur autre chose ? »

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
            $prompt .= "\n\n# Consignes spécifiques de l'entreprise (prioritaires)\n{$business->ai_instructions}";
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
