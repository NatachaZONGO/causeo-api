<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transcription d'un message vocal par Gemini (services.gemini.transcription_model),
 * avec la langue détectée et un indicateur « compris ».
 */
class VoiceTranscriptionService
{
    /** Tokens audio comptés par Gemini pour une seconde de son. */
    public const AUDIO_TOKENS_PER_SECOND = 32;

    private const PROMPT = <<<'PROMPT'
Transcris fidèlement ce message vocal WhatsApp, envoyé par un client à un commerce (Burkina Faso).
- "transcript" : le texte exact, dans la langue parlée, sans traduire, sans résumer, sans rien ajouter. Chaîne vide si rien n'est audible.
- "language" : code ISO 639-1 de la langue principale (fr, en…), ou ISO 639-3 s'il n'y en a pas (mos pour le mooré, dyu pour le dioula, fuv pour le fulfuldé…). "und" si impossible à dire.
- "understood" : true seulement si le message est intelligible et que la transcription est fiable ; false s'il est inaudible, trop bruité, coupé ou incompréhensible.
PROMPT;

    /**
     * @return array{transcript: string, language: string, understood: bool, usage: array<string, int|string>}
     *
     * @throws RuntimeException si Gemini échoue ou répond sans JSON exploitable
     */
    public function transcribe(string $path, string $mimeType): array
    {
        $model = (string) config('services.gemini.transcription_model', 'gemini-2.5-flash');

        $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
            ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
            ->timeout(90)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['inline_data' => ['mime_type' => self::geminiMimeType($mimeType), 'data' => base64_encode((string) file_get_contents($path))]],
                        ['text' => self::PROMPT],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'transcript' => ['type' => 'STRING'],
                            'language' => ['type' => 'STRING'],
                            'understood' => ['type' => 'BOOLEAN'],
                        ],
                        'required' => ['transcript', 'language', 'understood'],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Transcription refusée par Gemini (HTTP {$response->status()}).");
        }

        $text = collect((array) $response->json('candidates.0.content.parts', []))->pluck('text')->implode('');
        $data = json_decode($text, true);

        if (! is_array($data) || ! array_key_exists('transcript', $data)) {
            throw new RuntimeException('Transcription illisible dans la réponse de Gemini.');
        }

        return [
            'transcript' => trim((string) $data['transcript']),
            'language' => strtolower(trim((string) ($data['language'] ?? 'und'))) ?: 'und',
            'understood' => (bool) ($data['understood'] ?? false),
            'usage' => $this->usage($model, (array) $response->json('usageMetadata', [])),
        ];
    }

    /**
     * Tokens consommés, en séparant l'audio (plus cher) du texte.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, int|string>
     */
    private function usage(string $model, array $metadata): array
    {
        $audio = 0;
        foreach ((array) ($metadata['promptTokensDetails'] ?? []) as $detail) {
            if (($detail['modality'] ?? null) === 'AUDIO') {
                $audio += (int) ($detail['tokenCount'] ?? 0);
            }
        }

        $prompt = (int) ($metadata['promptTokenCount'] ?? 0);

        return [
            'model' => $model,
            'input_audio_tokens' => $audio,
            'input_text_tokens' => max(0, $prompt - $audio),
            // Le raisonnement (thoughts) est facturé comme la sortie.
            'output_tokens' => (int) ($metadata['candidatesTokenCount'] ?? 0) + (int) ($metadata['thoughtsTokenCount'] ?? 0),
        ];
    }

    /** « audio/ogg; codecs=opus » → « audio/ogg » (Gemini n'accepte pas les paramètres). */
    private static function geminiMimeType(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType)[0])) ?: 'audio/ogg';
    }
}
