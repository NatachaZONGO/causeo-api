<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sonde TEMPORAIRE, pour observer ce que Meta envoie (réponses aux statuts, coexistence), sans
 * rien changer au traitement. Active seulement avec LOG_STATUS_PROBE=true ; écrit dans
 * storage/logs/status-probe.log (canal status_probe).
 *
 * Seule la STRUCTURE est gardée : noms des champs, types, nombres, présence d'un média,
 * identifiants tronqués. Jamais de texte, de nom, de numéro complet ni d'image. À retirer
 * une fois l'observation finie.
 */
final class StatusProbe
{
    /** Profondeur maximale de la structure journalisée. */
    private const MAX_DEPTH = 8;

    /** Valeurs gardées telles quelles : des types et des noms de champ définis par Meta, jamais du contenu. */
    private const ENUM_KEYS = ['type', 'field', 'messaging_product', 'status', 'source_type', 'media_type', 'event'];

    /** Champs qui portent un média. */
    private const MEDIA_KEYS = ['image', 'video', 'audio', 'document', 'sticker'];

    public static function enabled(): bool
    {
        return (bool) config('services.whatsapp.status_probe', false);
    }

    /**
     * Webhook d'un business en coexistence qui n'est pas un message client classique
     * (smb_message_echoes, history, smb_app_state_sync, statuts…).
     *
     * @param  array<string, mixed>  $value  entry.0.changes.0.value
     */
    public static function coexistenceEvent(string $field, array $value, string $businessId): void
    {
        self::write('webhook hors message client', [
            'business_id' => $businessId,
            'field' => $field,
            'keys' => array_keys($value),
            'items' => self::items($value),
            'structure' => self::structure($value),
        ]);
    }

    /**
     * Message client en réponse à un message inconnu (context.id introuvable) : statut WhatsApp,
     * message trop ancien…
     *
     * @param  array<string, mixed>  $raw  messages[0] du webhook
     */
    public static function unknownContext(array $raw, ?string $businessId): void
    {
        $context = is_array($raw['context'] ?? null) ? $raw['context'] : [];

        self::write('message client, context.id inconnu', [
            'business_id' => $businessId,
            'context_id' => is_scalar($context['id'] ?? null) ? (string) $context['id'] : null,
            'context_from' => self::phone($context['from'] ?? null),
            'type' => is_string($raw['type'] ?? null) ? $raw['type'] : null,
            'keys' => array_keys($raw),
            'context_keys' => array_keys($context),
            'has_media' => self::hasMedia($raw),
            'structure' => self::structure($raw),
        ]);
    }

    /**
     * Éléments des listes du webhook (message_echoes, messages, statuses, history…) : type,
     * destinataire tronqué, présence d'un média, identifiant tronqué, champs présents.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, list<array<string, mixed>>>
     */
    private static function items(array $value): array
    {
        $items = [];

        foreach ($value as $key => $list) {
            if (! is_string($key) || ! is_array($list) || ! array_is_list($list) || $key === 'contacts') {
                continue;
            }

            foreach (array_slice($list, 0, 5) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items[$key][] = array_filter([
                    'type' => is_string($item['type'] ?? null) ? $item['type'] : null,
                    'to' => self::phone($item['to'] ?? $item['recipient_id'] ?? null),
                    'has_media' => self::hasMedia($item),
                    'id' => self::truncate($item['id'] ?? null),
                    'has_context' => isset($item['context']) ? true : null,
                    'keys' => array_keys($item),
                ], fn ($field) => $field !== null);
            }
        }

        return $items;
    }

    /**
     * Noms des champs, imbriqués : chaque valeur est remplacée par son type (« string(12) »,
     * « int », « list(3) »…), sauf les types définis par Meta (ENUM_KEYS). Une liste est
     * décrite par son premier élément.
     */
    private static function structure(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return 'array(…)';
            }

            if (array_is_list($value)) {
                return $value === [] ? 'list(0)' : ['list('.count($value).')' => self::structure($value[0], $depth + 1)];
            }

            $shape = [];
            foreach ($value as $childKey => $child) {
                $shape[(string) $childKey] = self::structure($child, $depth + 1, (string) $childKey);
            }

            return $shape;
        }

        if (is_string($value) && in_array($key, self::ENUM_KEYS, true) && preg_match('/^[a-z_]{1,40}$/', $value) === 1) {
            return $value;
        }

        return match (true) {
            is_string($value) => 'string('.mb_strlen($value).')',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value === null => 'null',
            default => gettype($value),
        };
    }

    /** @param  array<string, mixed>  $item */
    private static function hasMedia(array $item): bool
    {
        return array_intersect(array_keys($item), self::MEDIA_KEYS) !== [];
    }

    /** « 22670112233 » → « 226…33 » ; jamais le numéro complet. */
    private static function phone(mixed $phone): ?string
    {
        $digits = preg_replace('/\D/', '', is_scalar($phone) ? (string) $phone : '');

        return $digits === '' ? null : substr($digits, 0, 3).'…'.substr($digits, -2);
    }

    /** Identifiant (wamid…) réduit à ses 16 premiers caractères. */
    private static function truncate(mixed $id): ?string
    {
        return is_scalar($id) && (string) $id !== '' ? substr((string) $id, 0, 16).'…' : null;
    }

    /** @param  array<string, mixed>  $context */
    private static function write(string $message, array $context): void
    {
        if (! self::enabled()) {
            return;
        }

        // Une sonde ne doit jamais gêner le traitement du webhook.
        try {
            Log::channel('status_probe')->info("STATUS_PROBE {$message}", $context);
        } catch (Throwable) {
        }
    }
}
