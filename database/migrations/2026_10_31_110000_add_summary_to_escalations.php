<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Résumé de l'escalade pour le gérant (EscalationSummaryService) : client, demande, ce qui
 * manque à l'assistant et une suggestion de réponse. Affiché dans la notification et l'alerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->text('summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
