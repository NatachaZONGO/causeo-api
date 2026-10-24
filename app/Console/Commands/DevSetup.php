<?php

namespace App\Console\Commands;

use Database\Seeders\BusinessTemplateSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Préparer la base de développement (projet Supabase causeo-dev) : migrations, formules,
 * templates et données de démonstration. Refusé en production ; la protection
 * ProductionGuard empêche déjà de lancer artisan sur la base de production en local.
 */
class DevSetup extends Command
{
    protected $signature = 'causeo:dev-setup
                            {--fresh : Supprimer toutes les tables avant de migrer}
                            {--without-demo : Ne pas créer les données de démonstration}';

    protected $description = 'Préparer la base de développement : migrations, seeders et données de démonstration';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('causeo:dev-setup ne s\'exécute jamais en production.');

            return self::FAILURE;
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Base PostgreSQL requise (pgvector) : renseignez le projet Supabase causeo-dev dans le .env (voir DEVELOPPEMENT.md).');

            return self::FAILURE;
        }

        $database = DB::connection()->getConfig('host').' / '.DB::connection()->getConfig('username');

        if ($this->option('fresh') && ! $this->confirm("Supprimer TOUTES les tables de {$database} ?", ! $this->input->isInteractive())) {
            return self::FAILURE;
        }

        $this->components->info("Base : {$database}");

        $this->call($this->option('fresh') ? 'migrate:fresh' : 'migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => BusinessTemplateSeeder::class, '--force' => true]);

        if (! $this->option('without-demo')) {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

            $this->components->info('Comptes de démonstration (mot de passe : '.DemoSeeder::PASSWORD.')');
            $this->components->bulletList([
                'boutique@'.DemoSeeder::DOMAIN.' : Awa Couture (mode, commandes)',
                'coiffure@'.DemoSeeder::DOMAIN.' : Salon Belle Tresse (coiffure, rendez-vous)',
                'admin@'.DemoSeeder::DOMAIN.' : administrateur Causeo',
            ]);
        }

        return self::SUCCESS;
    }
}
