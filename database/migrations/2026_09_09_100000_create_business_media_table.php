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
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('business_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['image', 'document', 'catalog']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('public_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->json('keywords');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
            $table->index(['business_id', 'is_active']);
        });

        // Dimension alignée sur EmbeddingService (Gemini, outputDimensionality 1536),
        // comme document_chunks.embedding et learned_responses.question_embedding.
        DB::statement('ALTER TABLE business_media ADD COLUMN keywords_embedding vector(1536)');
        DB::statement('CREATE INDEX business_media_keywords_embedding_idx ON business_media USING hnsw (keywords_embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_media');
    }
};
