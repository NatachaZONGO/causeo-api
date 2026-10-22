<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Models\ManagerReply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Profil de style d'un business, extrait par Claude des dernières réponses écrites
 * par son gérant (jamais celles d'un autre business, jamais celles du bot).
 */
class StyleProfileService
{
    /** Pas de profil en dessous de ce nombre de réponses du gérant. */
    public const MIN_REPLIES = 10;

    /** Nouveau profil toutes les REFRESH_EVERY nouvelles réponses… */
    public const REFRESH_EVERY = 20;

    /** … et au plus une fois par période (heures). */
    public const MIN_HOURS_BETWEEN_REFRESHES = 24;

    /** Réponses envoyées à Claude pour établir le profil. */
    public const SAMPLE_SIZE = 50;

    private const REPLY_MAX_LENGTH = 400;

    private const QUESTION_MAX_LENGTH = 200;

    /**
     * Un nouveau profil est-il dû : apprentissage activé, assez de réponses, et
     * REFRESH_EVERY nouvelles réponses depuis le dernier profil, pas plus d'une fois par jour ?
     */
    public function isDue(Business $business): bool
    {
        if (! $business->learnsFromOwner()) {
            return false;
        }

        $updatedAt = $business->style_profile_updated_at;

        if ($updatedAt === null || $business->style_profile === null) {
            return $business->managerReplies()->count() >= self::MIN_REPLIES;
        }

        if ($updatedAt->gt(now()->subHours(self::MIN_HOURS_BETWEEN_REFRESHES))) {
            return false;
        }

        return $business->managerReplies()->where('created_at', '>', $updatedAt)->count() >= self::REFRESH_EVERY;
    }

    /**
     * Établir le profil à partir des SAMPLE_SIZE dernières réponses du gérant et
     * l'enregistrer. Renvoie null (sans appel à Claude) sous MIN_REPLIES réponses.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException si Claude ne renvoie pas de profil exploitable
     */
    public function refresh(Business $business): ?array
    {
        $replies = $business->managerReplies()->latest()->limit(self::SAMPLE_SIZE)->get()->reverse()->values();

        if ($replies->count() < self::MIN_REPLIES) {
            return null;
        }

        $payload = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-haiku-4-5'),
                'max_tokens' => 800,
                'system' => $this->instructions(),
                'messages' => [['role' => 'user', 'content' => $this->samples($replies)]],
            ])
            ->throw()
            ->json();

        $text = collect($payload['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n");
        $extracted = $this->decode($text);

        if ($extracted === null) {
            throw new RuntimeException('Profil de style illisible dans la réponse de Claude.');
        }

        $profile = $this->normalize($extracted) + [
            'median_words' => $this->medianWords($replies->pluck('reply')->all()),
            'sample_size' => $replies->count(),
        ];

        $business->forceFill(['style_profile' => $profile, 'style_profile_updated_at' => now()])->save();

        Log::info('StyleProfileService: profil de style mis à jour.', [
            'business_id' => $business->id,
            'sample_size' => $replies->count(),
            'input_tokens' => $payload['usage']['input_tokens'] ?? null,
            'output_tokens' => $payload['usage']['output_tokens'] ?? null,
        ]);

        return $profile;
    }

    /**
     * Longueur médiane des réponses, en mots.
     *
     * @param  list<string>  $replies
     */
    public function medianWords(array $replies): int
    {
        $counts = array_map(fn (string $reply) => count(preg_split('/\s+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY) ?: []), $replies);
        sort($counts);
        $n = count($counts);

        if ($n === 0) {
            return 0;
        }

        return (int) round($n % 2 === 1 ? $counts[intdiv($n, 2)] : ($counts[$n / 2 - 1] + $counts[$n / 2]) / 2);
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
Tu analyses la façon d'écrire du gérant d'un commerce à ses clients sur WhatsApp, à partir de ses vraies réponses. Décris uniquement son style, jamais les faits (prix, produits, stocks, délais) : ils changent.

Réponds UNIQUEMENT par un objet JSON, sans texte autour, avec ces clés :
- "address" : "tu", "vous" ou "mixte"
- "greetings" : salutations habituelles (au plus 5, telles qu'il les écrit), [] s'il ne salue pas
- "closings" : formules de fin habituelles (au plus 5), [] s'il n'en a pas
- "emoji_usage" : "jamais", "parfois" ou "souvent"
- "emojis" : emojis qu'il utilise le plus (au plus 5)
- "expressions" : expressions récurrentes qui lui sont propres (au plus 8)
- "languages" : langue(s) utilisée(s), par exemple ["français"] ou ["français", "mooré"]
- "tone" : son ton en quelques mots (par exemple « direct et chaleureux »)
- "instructions" : un paragraphe de 3 à 5 phrases, à la deuxième personne, qui dit à un assistant comment écrire exactement comme lui (registre, longueur, ponctuation, emojis, formules, langue). Pas d'exemple de prix ni de produit.
PROMPT;
    }

    /**
     * @param  Collection<int, ManagerReply>  $replies
     */
    private function samples($replies): string
    {
        $lines = $replies->map(function (ManagerReply $reply, int $i) {
            $question = $reply->customer_question !== null
                ? 'Client : '.mb_substr(trim($reply->customer_question), 0, self::QUESTION_MAX_LENGTH)."\n"
                : '';

            return ($i + 1).".\n{$question}Gérant : ".mb_substr(trim($reply->reply), 0, self::REPLY_MAX_LENGTH);
        });

        return "Réponses du gérant, de la plus ancienne à la plus récente :\n\n".$lines->implode("\n\n");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $match) !== 1) {
            return null;
        }

        $decoded = json_decode($match[0], true);

        return is_array($decoded) && is_string($decoded['instructions'] ?? null) && trim($decoded['instructions']) !== '' ? $decoded : null;
    }

    /**
     * Ne garder que les clés attendues, avec des tailles bornées (le profil va dans le prompt).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $list = fn (string $key, int $max) => array_values(array_slice(array_filter(
            array_map(fn ($item) => is_string($item) ? mb_substr(trim($item), 0, 60) : '', (array) ($data[$key] ?? [])),
        ), 0, $max));
        $choice = fn (string $key, array $allowed) => in_array($data[$key] ?? null, $allowed, true) ? $data[$key] : null;

        return [
            'address' => $choice('address', ['tu', 'vous', 'mixte']),
            'greetings' => $list('greetings', 5),
            'closings' => $list('closings', 5),
            'emoji_usage' => $choice('emoji_usage', ['jamais', 'parfois', 'souvent']),
            'emojis' => $list('emojis', 5),
            'expressions' => $list('expressions', 8),
            'languages' => $list('languages', 3),
            'tone' => is_string($data['tone'] ?? null) ? mb_substr(trim($data['tone']), 0, 100) : null,
            'instructions' => mb_substr(trim((string) $data['instructions']), 0, 1200),
        ];
    }
}
