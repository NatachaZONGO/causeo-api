<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Avant tout le reste, requêtes HTTP comme commandes artisan : jamais la base ou le
        // stockage de production depuis un poste de développement.
        // Seule la connexion utilisée compte (les tests tournent sur SQLite avec le .env du poste).
        $connection = (string) config('database.default');

        ProductionGuard::check((string) $this->app->environment(), [
            'database' => [$connection => (array) config("database.connections.{$connection}", [])],
            'disks' => (array) config('filesystems.disks', []),
            'env' => ['SUPABASE_URL' => env('SUPABASE_URL')],
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
