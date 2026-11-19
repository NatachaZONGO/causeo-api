<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commande de l'assistant restée « new » : remise en stock et annulation automatiques après
     * ce délai (heures, 24 par défaut ; 0 : jamais). Voir la commande orders:expire.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedSmallInteger('order_expiry_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('order_expiry_hours');
        });
    }
};
