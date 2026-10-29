<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consentement aux alertes WhatsApp : la version du texte accepté (WhatsAppAlertController::
     * CONSENT_VERSION) est enregistrée avec sa date, pour prouver ce que le gérant a accepté.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_alerts_consent_version', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp_alerts_consent_version');
        });
    }
};
