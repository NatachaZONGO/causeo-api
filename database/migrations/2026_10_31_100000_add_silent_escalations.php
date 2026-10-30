<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalade silencieuse : quand l'assistant ne sait pas, le client ne reçoit rien et le gérant
 * est prévenu. Sans réponse du gérant après escalation_fallback_minutes (0 = jamais), un seul
 * message d'attente neutre part au client (fallback_due_at, fallback_sent_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->boolean('silent')->default(false);
            $table->timestamp('fallback_due_at')->nullable();
            $table->timestamp('fallback_sent_at')->nullable();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_fallback_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->dropColumn(['silent', 'fallback_due_at', 'fallback_sent_at']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('escalation_fallback_minutes');
        });
    }
};
