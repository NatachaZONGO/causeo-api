<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des accès d'un admin aux données d'un business (écoute d'un message
     * vocal…), sans leur contenu. Sans clé étrangère vers le message ni le business :
     * l'entrée reste même s'ils sont supprimés.
     */
    public function up(): void
    {
        Schema::create('admin_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // voice_message_listened
            $table->uuid('message_id')->nullable();
            $table->uuid('business_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE admin_access_logs ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_access_logs');
    }
};
