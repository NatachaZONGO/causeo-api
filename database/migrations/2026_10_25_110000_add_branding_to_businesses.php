<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personnalisation du business : logo téléversé (chemin dans le bucket media, l'URL
     * publique est calculée) et couleur de marque (#RRGGBB). La RLS est déjà active sur
     * la table businesses.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'brand_color']);
        });
    }
};
