<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('whatsapp_phone')->nullable()->after('phone');
            $table->string('city')->nullable()->after('whatsapp_phone');
            $table->string('country')->default('BF')->after('city');
        });

        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('BF');
            $table->string('logo_url')->nullable();
            $table->json('opening_hours')->nullable();
            $table->text('custom_greeting')->nullable();
            $table->text('ai_instructions')->nullable();
            $table->string('plan')->default('free');
            $table->integer('monthly_message_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
            $table->index('whatsapp_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'whatsapp_phone', 'city', 'country']);
        });
    }
};
