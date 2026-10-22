<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dernière lecture de la conversation par le gérant (dashboard, ou réponse depuis
     * son téléphone) : les messages entrants plus récents sont « non lus ». Les
     * conversations existantes sont considérées comme lues, pour ne pas afficher tout
     * l'historique en non lu au déploiement.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable();
        });

        DB::table('conversations')->update(['last_read_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
