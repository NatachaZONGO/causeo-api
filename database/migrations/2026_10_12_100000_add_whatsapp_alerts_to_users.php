<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alertes WhatsApp au gérant, envoyées depuis le numéro Causeo : numéro au
     * format international, option (désactivée par défaut) et date du consentement.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('notification_phone', 20)->nullable();
            $table->boolean('whatsapp_alerts_enabled')->default(false);
            $table->timestamp('whatsapp_alerts_consented_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notification_phone', 'whatsapp_alerts_enabled', 'whatsapp_alerts_consented_at']);
        });
    }
};
