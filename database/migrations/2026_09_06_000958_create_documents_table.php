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

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('file_type'); // pdf, txt, docx, csv
            $table->integer('file_size')->default(0);
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->integer('chunk_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('status');
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->integer('chunk_index');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('document_id');
        });

        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
