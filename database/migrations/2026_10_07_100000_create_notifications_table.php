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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['escalation', 'order']);
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'read_at']);
        });

        // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
        // Laravel se connecte avec le rôle propriétaire de la table, qui n'est pas concerné.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
