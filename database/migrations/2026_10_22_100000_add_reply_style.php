<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Style de réponse du bot : réglages du gérant, profil appris de ses réponses,
     * et ses réponses indexées pour servir d'exemples de ton.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Réglages explicites (tutoiement, emojis, longueur, signature, apprentissage).
            $table->json('reply_style')->nullable();
            // Profil extrait par StyleProfileService des réponses du gérant.
            $table->json('style_profile')->nullable();
            $table->timestamp('style_profile_updated_at')->nullable();
        });

        Schema::create('manager_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->nullable()->unique()->constrained('messages')->nullOnDelete();
            // Message(s) du client auxquels le gérant répond, s'il y en a.
            $table->text('customer_question')->nullable();
            $table->text('reply');
            $table->string('source'); // dashboard | echo | history
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Même dimension que learned_responses.question_embedding (EmbeddingService).
            DB::statement('ALTER TABLE manager_replies ADD COLUMN question_embedding vector(1536)');
            DB::statement('CREATE INDEX manager_replies_embedding_idx ON manager_replies USING hnsw (question_embedding vector_cosine_ops)');
            DB::statement('ALTER TABLE manager_replies ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manager_replies');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['reply_style', 'style_profile', 'style_profile_updated_at']);
        });
    }
};
