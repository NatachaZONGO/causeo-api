<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Résumé d'une escalade pour le gérant, en 2 ou 3 lignes (Claude Haiku) : qui est le client
 * (nom WhatsApp), ce qu'il demande, ce qui manque à l'assistant pour répondre, et une suggestion
 * de réponse si possible. Il figure dans la notification du dashboard et l'alerte WhatsApp.
 * Aucun numéro de téléphone n'est envoyé à Claude ni gardé dans le résumé ; rien du client
 * n'est écrit dans les logs.
 */
class EscalationSummaryService
{
    /** Messages de la conversation donnés à Claude, au plus (les plus récents). */
    private const HISTORY_MESSAGES = 10;

    /** Longueur maximale du résumé gardé. */
    public const MAX_LENGTH = 400;

    private const MAX_TOKENS = 200;

    private const INSTRUCTIONS = <<<'TEXT'
Tu aides le gérant d'une entreprise au Burkina Faso. L'assistant WhatsApp de l'entreprise n'a pas su répondre à un client : le client attend, le gérant va lui répondre lui-même.
Écris pour le gérant un résumé de 2 ou 3 lignes, en français, en le vouvoyant :
1. Qui est le client (son nom WhatsApp suivi de « (WhatsApp) », ou « Un client » sans nom) et ce qu'il demande, précisément (article, prix, date…).
2. « Il manque : » ce qu'il faudrait savoir pour lui répondre (par exemple « vos créneaux disponibles », « le prix de cette coiffure »).
3. Si possible, « Suggestion : » une réponse courte et concrète, tirée seulement des informations fournies (par exemple deux créneaux pris dans les horaires). Sans information utile, omets cette ligne.
Exemple : « Arlette (WhatsApp) veut la coiffure du statut « tout fourni à 10 000 F » et demande quand passer.
Il manque : vos créneaux disponibles.
Suggestion : proposer demain 10h ou samedi 15h. »
N'invente aucun prix ni aucune information. N'écris jamais de numéro de téléphone. Pas de titre, pas de Markdown, pas de formule de politesse : seulement les lignes du résumé.
TEXT;

    /**
     * Résumé de la demande, ou null si Claude ne répond pas (l'escalade part quand même).
     */
    public function summarize(Business $business, Conversation $conversation, string $question, ?string $reason = null): ?string
    {
        if (! config('services.anthropic.escalation_summary', true) || empty(config('services.anthropic.api_key'))) {
            return null;
        }

        try {
            $payload = Http::withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
                ->timeout(20)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.summary_model', 'claude-haiku-4-5'),
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => self::INSTRUCTIONS,
                    'messages' => [['role' => 'user', 'content' => $this->context($business, $conversation, $question, $reason)]],
                ])
                ->throw()
                ->json();

            $summary = self::clean(collect($payload['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n"));

            Log::info('EscalationSummaryService: résumé de l\'escalade rédigé', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'length' => mb_strlen((string) $summary),
                'input_tokens' => data_get($payload, 'usage.input_tokens'),
                'output_tokens' => data_get($payload, 'usage.output_tokens'),
            ]);

            return $summary;
        } catch (Throwable $e) {
            // Pas de getMessage() : la requête contient la conversation du client.
            Log::warning('EscalationSummaryService: résumé non rédigé', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Ce que Claude reçoit : l'entreprise, ses horaires et des créneaux possibles, le nom
     * WhatsApp du client, les derniers messages et la demande en attente. Aucun numéro.
     */
    private function context(Business $business, Conversation $conversation, string $question, ?string $reason): string
    {
        $now = CarbonImmutable::now($business->localTimezone());
        $type = $business->type instanceof \BackedEnum ? $business->type->value : $business->type;
        $slots = array_map(fn (CarbonImmutable $slot) => OpeningHours::describe($slot, $now), OpeningHours::suggestSlots($business->opening_hours, $now));
        $name = self::withoutPhoneNumbers(trim((string) $conversation->customer_name));

        $history = $conversation->messages()
            ->latest()->orderByDesc('id')
            ->limit(self::HISTORY_MESSAGES)
            ->get(['direction', 'sender_type', 'content'])
            ->reverse()
            ->map(fn ($message) => ($message->direction === 'inbound' ? 'Client' : ($message->sender_type === 'human' ? 'Gérant' : 'Assistant'))
                .' : '.Str::limit(self::withoutPhoneNumbers(trim((string) $message->content)), 300))
            ->implode("\n");

        return implode("\n", array_filter([
            "Entreprise : {$business->name}".($type ? " ({$type})" : ''),
            $slots !== [] ? 'Créneaux possibles pendant les horaires d\'ouverture : '.implode(' ou ', $slots).'.' : null,
            'Nom WhatsApp du client : '.($name !== '' ? $name : 'inconnu'),
            $reason === Escalation::REASON_STATUS_REPLY ? 'Le client répond à un statut WhatsApp publié hors Causeo : l\'assistant ne sait pas de quel article il parle. Précisez-le dans le résumé (« réponse à un statut publié hors Causeo »).' : null,
            $history !== '' ? "Derniers messages :\n{$history}" : null,
            'Demande en attente : '.Str::limit(self::withoutPhoneNumbers(trim($question)), 600),
        ]));
    }

    /** Résumé nettoyé : sans numéro, sans Markdown ni guillemets d'enrobage, 3 lignes au plus. */
    private static function clean(string $text): ?string
    {
        $text = self::withoutPhoneNumbers(str_replace(['**', '*', '#'], '', $text));
        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []))), 0, 3);
        $summary = trim(implode("\n", $lines), " \t\n«»\"");

        return $summary === '' ? null : Str::limit($summary, self::MAX_LENGTH);
    }

    /** « +226 70 11 22 33 », « 70112233 » → « [numéro] » (8 chiffres au moins : pas un prix comme « 1 500 000 »). */
    public static function withoutPhoneNumbers(string $text): string
    {
        return (string) preg_replace_callback(
            '/\+?\d[\d .\-]{6,}\d/u',
            fn (array $match) => strlen((string) preg_replace('/\D/', '', $match[0])) >= 8 ? '[numéro]' : $match[0],
            $text,
        );
    }
}
