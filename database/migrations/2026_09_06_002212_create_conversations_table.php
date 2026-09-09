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
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->string('whatsapp_conversation_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'customer_phone']);
            $table->index('customer_phone');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('sender_type', ['customer', 'ai', 'human']);
            $table->text('content');
            $table->string('status')->default('pending');
            $table->float('confidence_score')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('status');
        });

        Schema::create('escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->text('customer_question');
            $table->text('human_response')->nullable();
            $table->enum('status', ['pending', 'answered', 'ignored'])->default('pending');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('learned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('escalation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->integer('usage_count')->default(0);
            $table->timestamps();

            $table->index('business_id');
        });

        DB::statement('ALTER TABLE learned_responses ADD COLUMN question_embedding vector(1536)');
        DB::statement('CREATE INDEX learned_responses_embedding_idx ON learned_responses USING hnsw (question_embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_responses');
        Schema::dropIfExists('escalations');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
