<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date du dernier export « nouveaux clients » (vCard) : l'export suivant ne reprend que les
     * conversations créées après. businesses a déjà sa RLS.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('contacts_exported_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('contacts_exported_at');
        });
    }
};
