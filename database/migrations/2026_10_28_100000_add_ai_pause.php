<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pause de l'assistant dans une conversation quand le gérant prend la main : jusqu'à
     * ai_paused_until (9999-12-31 : jusqu'à réactivation manuelle). Durée de la pause
     * automatique par business, en heures (0 : jusqu'à réactivation manuelle).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_paused_until')->nullable();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedSmallInteger('ai_pause_hours')->default(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_paused_until');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('ai_pause_hours');
        });
    }
};
