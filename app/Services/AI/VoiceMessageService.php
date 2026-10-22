<?php

namespace App\Services\AI;

use App\Models\Business;
use App\Services\WhatsApp\MediaTooLargeException;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Message vocal d'un client : téléchargement chez Meta (fichier temporaire supprimé
 * après usage), durée, transcription par Gemini. Première version : seul un vocal en
 * français, compris, est traité comme un message écrit ; les autres vont au gérant.
 */
class VoiceMessageService
{
    public const MAX_SECONDS = 180;

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Langues que le bot traite à partir d'un vocal. */
    public const SUPPORTED_LANGUAGES = ['fr'];

    /** Noms des langues, pour l'escalade (« Message vocal en mooré »). */
    private const LANGUAGE_NAMES = [
        'fr' => 'français', 'en' => 'anglais', 'mos' => 'mooré', 'dyu' => 'dioula', 'bm' => 'bambara',
        'ff' => 'fulfuldé', 'ful' => 'fulfuldé', 'fuv' => 'fulfuldé', 'fuh' => 'fulfuldé', 'gux' => 'gourmantché',
        'bib' => 'bissa', 'dag' => 'dagbani', 'ha' => 'haoussa', 'wo' => 'wolof', 'tw' => 'twi', 'ak' => 'akan',
        'yo' => 'yoruba', 'ar' => 'arabe', 'es' => 'espagnol', 'pt' => 'portugais', 'de' => 'allemand',
        'it' => 'italien', 'zh' => 'chinois', 'tr' => 'turc', 'ru' => 'russe', 'hi' => 'hindi',
    ];

    public function __construct(
        private readonly WhatsAppMediaService $media,
        private readonly VoiceTranscriptionService $transcriber,
    ) {
    }

    public function handle(Business $business, string $mediaId, string $mimeType): VoiceMessageResult
    {
        try {
            $file = $this->media->download($business, $mediaId, self::MAX_BYTES);
        } catch (MediaTooLargeException) {
            return new VoiceMessageResult(VoiceMessageResult::TOO_LONG);
        } catch (Throwable $e) {
            Log::warning('VoiceMessageService: téléchargement du vocal impossible.', ['business_id' => $business->id, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return new VoiceMessageResult(VoiceMessageResult::FAILED);
        }

        $path = tempnam(sys_get_temp_dir(), 'causeo-voice-');

        try {
            file_put_contents($path, $file['body']);
            unset($file['body']);

            $duration = self::oggOpusDuration($path);
            if ($duration !== null && $duration > self::MAX_SECONDS) {
                return new VoiceMessageResult(VoiceMessageResult::TOO_LONG, durationSeconds: $duration);
            }

            try {
                $transcription = $this->transcriber->transcribe($path, $file['mime_type'] ?: $mimeType);
            } catch (Throwable $e) {
                Log::warning('VoiceMessageService: transcription impossible.', ['business_id' => $business->id, 'exception' => $e::class, 'message' => $e->getMessage()]);

                return new VoiceMessageResult(VoiceMessageResult::FAILED, durationSeconds: $duration);
            }
        } finally {
            @unlink($path);
        }

        $usage = $transcription['usage'];
        // Hors OGG/Opus, la durée vient des tokens audio comptés par Gemini (32 par seconde).
        $duration ??= $usage['input_audio_tokens'] > 0 ? (int) round($usage['input_audio_tokens'] / VoiceTranscriptionService::AUDIO_TOKENS_PER_SECOND) : null;
        $transcript = $transcription['transcript'] !== '' ? $transcription['transcript'] : null;
        $language = $transcription['language'];

        $status = match (true) {
            $duration !== null && $duration > self::MAX_SECONDS => VoiceMessageResult::TOO_LONG,
            ! $transcription['understood'] || $transcript === null => VoiceMessageResult::UNINTELLIGIBLE,
            ! in_array($language, self::SUPPORTED_LANGUAGES, true) => VoiceMessageResult::OTHER_LANGUAGE,
            default => VoiceMessageResult::TRANSCRIBED,
        };

        return new VoiceMessageResult($status, $transcript, $language, $duration, $usage);
    }

    public static function languageName(?string $code): string
    {
        return self::LANGUAGE_NAMES[$code ?? ''] ?? 'langue non reconnue'.($code !== null && $code !== 'und' ? " ({$code})" : '');
    }

    /**
     * Durée d'un fichier OGG/Opus (notes vocales WhatsApp), lue dans la dernière page :
     * position (granule, en échantillons à 48 kHz) moins le pre-skip de l'en-tête Opus.
     * Null pour un autre format.
     */
    public static function oggOpusDuration(string $path): ?int
    {
        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false || $size < 64) {
            return null;
        }

        try {
            $head = (string) fread($handle, 4096);
            $opusHead = strpos($head, 'OpusHead');

            if (! str_starts_with($head, 'OggS') || $opusHead === false) {
                return null;
            }

            $preSkip = unpack('v', substr($head, $opusHead + 10, 2))[1] ?? 0;

            fseek($handle, max(0, $size - 65536));
            $tail = (string) fread($handle, 65536);
            $last = strrpos($tail, 'OggS');

            if ($last === false || strlen($tail) < $last + 14) {
                return null;
            }

            $granule = unpack('P', substr($tail, $last + 6, 8))[1] ?? 0;

            return $granule > $preSkip ? (int) round(($granule - $preSkip) / 48000) : null;
        } finally {
            fclose($handle);
        }
    }
}
