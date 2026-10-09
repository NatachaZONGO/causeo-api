<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('price_fcfa')->default(0);
            $table->unsignedInteger('period_days')->nullable(); // null : pas d'échéance
            $table->unsignedInteger('reply_limit')->nullable(); // null : réponses illimitées
            $table->enum('reply_limit_period', ['day', 'period'])->nullable();
            $table->unsignedInteger('document_limit')->nullable(); // null : documents illimités
            $table->json('modules'); // modules autorisés (voir Business::MODULES)
            $table->boolean('learning')->default(true); // apprentissage des réponses du gérant
            $table->json('features')->nullable(); // arguments affichés sur la page d'accueil
            $table->string('badge')->nullable(); // par exemple « Le plus choisi »
            $table->boolean('highlight_daily_price')->default(false); // prix journalier affiché en grand
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
        // Laravel se connecte avec le rôle propriétaire de la table, qui n'est pas concerné.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE plans ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
