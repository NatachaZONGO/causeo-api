<?php

namespace App\Services\AI;

/**
 * Issue du traitement d'un message vocal (VoiceMessageService).
 */
final class VoiceMessageResult
{
    /** En français et compris : traité comme un message écrit. */
    public const TRANSCRIBED = 'transcribed';

    public const OTHER_LANGUAGE = 'other_language';

    public const UNINTELLIGIBLE = 'unintelligible';

    /** Plus de 3 minutes ou plus de 10 Mo. */
    public const TOO_LONG = 'too_long';

    /** Téléchargement ou transcription impossible (erreur technique). */
    public const FAILED = 'failed';

    /**
     * @param  array<string, int|string>|null  $usage  tokens de la transcription (VoiceTranscriptionService)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $transcript = null,
        public readonly ?string $language = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?array $usage = null,
    ) {
    }

    /** Le bot peut répondre à partir de la transcription. */
    public function isUsable(): bool
    {
        return $this->status === self::TRANSCRIBED;
    }

    /** Motif de l'escalade (escalations.reason). */
    public function escalationReason(): string
    {
        return 'voice_'.$this->status;
    }

    /** Texte de l'escalade, repris dans la cloche, l'e-mail et l'alerte WhatsApp. */
    public function escalationText(): string
    {
        return match ($this->status) {
            self::OTHER_LANGUAGE => 'Message vocal en '.VoiceMessageService::languageName($this->language),
            self::UNINTELLIGIBLE => 'Message vocal incompréhensible',
            self::TOO_LONG => 'Message vocal trop long'.($this->durationSeconds !== null ? ' ('.self::duration($this->durationSeconds).')' : ''),
            default => 'Message vocal non transcrit',
        };
    }

    /**
     * Champs du message entrant (metadata), sans la transcription si elle n'existe pas.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return array_filter([
            'voice_status' => $this->status,
            'transcript' => $this->transcript,
            'language' => $this->language,
            'duration_seconds' => $this->durationSeconds,
        ], fn ($value) => $value !== null);
    }

    /** 75 → « 1 min 15 s ». */
    public static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes === 0 ? "{$rest} s" : "{$minutes} min".($rest > 0 ? " {$rest} s" : '');
    }
}
