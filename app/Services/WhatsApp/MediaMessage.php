<?php

namespace App\Services\WhatsApp;

use App\Models\Escalation;

/**
 * Photos, documents, vidéos et autocollants reçus sur WhatsApp (ou envoyés par le gérant
 * depuis son téléphone) : contenu affiché, metadata gardée pour relire le fichier chez
 * Meta (GET /messages/{id}/media), et textes des escalades.
 */
final class MediaMessage
{
    public const TYPES = ['image', 'document', 'video', 'sticker'];

    /** Statut d'un message entrant qui n'appelle ni réponse ni escalade (autocollant seul). */
    public const IGNORED_STATUS = 'ignored';

    /** Images que l'assistant regarde (les autres formats vont au gérant sans analyse). */
    public const ANALYZABLE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Contenu d'un message sans légende (dashboard, aperçu de la conversation). */
    public const PLACEHOLDERS = [
        'image' => '📷 Photo',
        'document' => '📄 Document',
        'video' => '🎬 Vidéo',
        'sticker' => '🙂 Autocollant',
    ];

    /** Réponse par défaut au client quand le gérant a activé la réponse aux médias non traités. */
    public const FALLBACK_MESSAGES = [
        'document' => 'Merci pour votre document ! Je le transmets tout de suite au responsable, qui vous répondra rapidement.',
        'video' => 'Merci pour votre vidéo ! Je la transmets tout de suite au responsable, qui vous répondra rapidement.',
    ];

    /**
     * Champs utiles d'un média du webhook (messages[0].image, .document…), ou null sans identifiant.
     *
     * @param  array<string, mixed>  $media
     * @return array{media_id: string, mime_type: string, filename?: string, caption?: string}|null
     */
    public static function fromPayload(string $type, array $media): ?array
    {
        if (empty($media['id'])) {
            return null;
        }

        return array_filter([
            'media_id' => (string) $media['id'],
            'mime_type' => (string) ($media['mime_type'] ?? ''),
            'filename' => trim((string) ($media['filename'] ?? '')),
            'caption' => trim((string) ($media['caption'] ?? '')),
        ], fn (string $value) => $value !== '') + ['mime_type' => 'application/octet-stream'];
    }

    /**
     * Texte du message : la légende, sinon le nom du document, sinon « 📷 Photo »…
     *
     * @param  array<string, mixed>  $media
     */
    public static function content(string $type, array $media): string
    {
        if (! empty($media['caption'])) {
            return (string) $media['caption'];
        }

        if ($type === 'document' && ! empty($media['filename'])) {
            return self::PLACEHOLDERS['document'].' '.$media['filename'];
        }

        return self::PLACEHOLDERS[$type] ?? '📎 Fichier';
    }

    /**
     * Metadata du message : de quoi afficher et relire le fichier chez Meta.
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    public static function metadata(string $type, array $media, ?int $size = null): array
    {
        return array_filter([
            'type' => $type,
            'media_id' => $media['media_id'] ?? null,
            'mime_type' => $media['mime_type'] ?? null,
            'filename' => $media['filename'] ?? null,
            'caption' => $media['caption'] ?? null,
            'size' => $size,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** Motif de l'escalade d'un document ou d'une vidéo (escalations.reason). */
    public static function escalationReason(string $type): string
    {
        return $type === 'video' ? Escalation::REASON_VIDEO : Escalation::REASON_DOCUMENT;
    }

    /**
     * Texte de l'escalade (cloche, e-mail, alerte WhatsApp) : « Document reçu : devis.pdf ».
     *
     * @param  array<string, mixed>  $media
     */
    public static function escalationText(string $type, array $media): string
    {
        $text = $type === 'video' ? 'Vidéo reçue' : 'Document reçu'.(! empty($media['filename']) ? " : {$media['filename']}" : '');

        return $text.(! empty($media['caption']) ? " (« {$media['caption']} »)" : '');
    }
}
