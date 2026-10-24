<?php

namespace App\Support;

use RuntimeException;

/**
 * Hors production, refuser de démarrer (application et commandes artisan) si la
 * configuration pointe sur le projet Supabase de PRODUCTION : base de données ou
 * stockage. Un poste de développement travaille sur le projet causeo-dev (voir
 * DEVELOPPEMENT.md).
 */
final class ProductionGuard
{
    /** Identifiant du projet Supabase de production (dans l'hôte, l'utilisateur du pooler, les URL). */
    public const PRODUCTION_PROJECT = 'ppqgsdljypddwfvbxbxz';

    /**
     * @throws RuntimeException si un réglage contient l'identifiant de production
     */
    public static function check(string $environment, array $config): void
    {
        if ($environment === 'production') {
            return;
        }

        $matches = self::productionSettings($config);

        if ($matches === []) {
            return;
        }

        throw new RuntimeException(
            "Causeo refuse de démarrer : APP_ENV={$environment} mais le .env pointe sur le projet Supabase de PRODUCTION "
            .'('.self::PRODUCTION_PROJECT.') via '.implode(', ', $matches).'. '
            .'Utilisez le projet causeo-dev (voir DEVELOPPEMENT.md), puis videz le cache de configuration '
            .'(supprimez bootstrap/cache/config.php s\'il existe).'
        );
    }

    /**
     * Réglages qui contiennent l'identifiant de production, nommés comme dans le .env.
     *
     * @param  array{database?: array<string, mixed>, disks?: array<string, mixed>, env?: array<string, mixed>}  $config
     * @return list<string>
     */
    public static function productionSettings(array $config): array
    {
        $found = [];

        foreach ((array) ($config['database'] ?? []) as $connection) {
            foreach (['url' => 'DB_URL', 'host' => 'DB_HOST', 'username' => 'DB_USERNAME', 'database' => 'DB_DATABASE'] as $key => $name) {
                if (self::isProduction($connection[$key] ?? null)) {
                    $found[$name] = true;
                }
            }
        }

        foreach ((array) ($config['disks'] ?? []) as $disk) {
            foreach (['endpoint' => 'SUPABASE_STORAGE_ENDPOINT', 'url' => 'SUPABASE_STORAGE_MEDIA_URL'] as $key => $name) {
                if (self::isProduction($disk[$key] ?? null)) {
                    $found[$name] = true;
                }
            }
        }

        foreach ((array) ($config['env'] ?? []) as $name => $value) {
            if (self::isProduction($value)) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
    }

    private static function isProduction(mixed $value): bool
    {
        return is_string($value) && stripos($value, self::PRODUCTION_PROJECT) !== false;
    }
}
