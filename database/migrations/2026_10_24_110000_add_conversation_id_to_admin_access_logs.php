<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lecture d'une conversation par un admin (conversation_viewed) : on garde la
     * conversation consultée, toujours sans son contenu.
     */
    public function up(): void
    {
        Schema::table('admin_access_logs', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable();
            $table->index(['admin_id', 'conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_access_logs', function (Blueprint $table) {
            $table->dropIndex(['admin_id', 'conversation_id', 'created_at']);
            $table->dropColumn('conversation_id');
        });
    }
};
