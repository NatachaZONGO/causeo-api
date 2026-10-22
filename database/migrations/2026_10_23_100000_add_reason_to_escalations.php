<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motif d'une escalade qui ne vient pas de l'IA : message vocal dans une autre langue
     * (voice_other_language), incompréhensible (voice_unintelligible), trop long
     * (voice_too_long) ou non transcrit (voice_failed). Null pour une escalade ordinaire.
     */
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->string('reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
