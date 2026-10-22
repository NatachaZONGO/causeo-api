<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réponse au client quand son message vocal n'est pas transcrit (autre langue,
     * incompréhensible, trop long, erreur). Désactivée par défaut : le bot reste
     * silencieux et seul le gérant est prévenu.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('voice_fallback_reply')->default(false);
            // Texte envoyé au client ; vide : texte par défaut.
            $table->string('voice_fallback_message', 300)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['voice_fallback_reply', 'voice_fallback_message']);
        });
    }
};
