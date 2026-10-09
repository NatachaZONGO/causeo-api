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
        // Rappels et rapports déjà envoyés : billing:sync tourne toutes les heures et
        // rattrape ce qui a été manqué, l'index unique garantit un seul envoi.
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // reminder_7, reminder_3, reminder_1, reminder_0, grace, downgraded, weekly_report
            $table->string('period_key'); // échéance concernée (ISO 8601) ou début de semaine du rapport
            $table->timestamp('sent_at');

            $table->unique(['business_id', 'kind', 'period_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscription_reminders ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
