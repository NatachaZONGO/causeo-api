<?php

namespace Tests\Feature;

use App\Support\ProductionGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * Environnement de développement séparé : protection contre la base et le stockage
 * de production, et commande de préparation de la base causeo-dev.
 */
class DevEnvironmentTest extends TestCase
{
    private const PROD = ProductionGuard::PRODUCTION_PROJECT;

    public function test_local_settings_pointing_to_the_production_project_are_refused(): void
    {
        $cases = [
            'DB_USERNAME' => ['database' => ['pgsql' => ['host' => 'aws-0-eu-central-1.pooler.supabase.com', 'username' => 'postgres.'.self::PROD]]],
            'DB_HOST' => ['database' => ['pgsql' => ['host' => 'db.'.self::PROD.'.supabase.co', 'username' => 'postgres']]],
            'DB_URL' => ['database' => ['pgsql' => ['url' => 'postgresql://postgres:x@db.'.self::PROD.'.supabase.co:5432/postgres']]],
            'SUPABASE_STORAGE_ENDPOINT' => ['disks' => ['supabase' => ['endpoint' => 'https://'.self::PROD.'.supabase.co/storage/v1/s3']]],
            'SUPABASE_STORAGE_MEDIA_URL' => ['disks' => ['supabase_media' => ['url' => 'https://'.strtoupper(self::PROD).'.supabase.co/storage/v1/object/public/media']]],
            'SUPABASE_URL' => ['env' => ['SUPABASE_URL' => 'https://'.self::PROD.'.supabase.co']],
        ];

        foreach ($cases as $setting => $config) {
            try {
                ProductionGuard::check('local', $config);
                $this->fail("{$setting} pointe sur la production et aurait dû être refusé.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Causeo refuse de démarrer : APP_ENV=local', $e->getMessage());
                $this->assertStringContainsString("via {$setting}.", $e->getMessage());
                $this->assertStringContainsString('causeo-dev (voir DEVELOPPEMENT.md)', $e->getMessage());
            }
        }
    }

    public function test_development_project_and_production_server_are_allowed(): void
    {
        $dev = [
            'database' => ['pgsql' => ['host' => 'aws-0-eu-central-1.pooler.supabase.com', 'username' => 'postgres.abcdefghijklmnopqrst']],
            'disks' => ['supabase_media' => ['endpoint' => 'https://abcdefghijklmnopqrst.supabase.co/storage/v1/s3']],
        ];
        ProductionGuard::check('local', $dev);

        // Le VPS (APP_ENV=production) utilise bien sûr la base de production.
        ProductionGuard::check('production', ['database' => ['pgsql' => ['username' => 'postgres.'.self::PROD]]]);

        $this->assertSame([], ProductionGuard::productionSettings($dev));
    }

    public function test_the_application_boots_with_the_test_configuration(): void
    {
        // La protection tourne au démarrage de chaque test : la configuration des tests est saine.
        $this->assertSame([], ProductionGuard::productionSettings([
            'database' => [config('database.default') => config('database.connections.'.config('database.default'))],
            'disks' => config('filesystems.disks'),
        ]));
    }

    public function test_dev_setup_needs_postgresql(): void
    {
        $this->artisan('causeo:dev-setup')
            ->expectsOutputToContain('Base PostgreSQL requise (pgvector)')
            ->assertFailed();
    }

    public function test_dev_setup_never_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('causeo:dev-setup')
            ->expectsOutputToContain('ne s\'exécute jamais en production')
            ->assertFailed();
    }
}
