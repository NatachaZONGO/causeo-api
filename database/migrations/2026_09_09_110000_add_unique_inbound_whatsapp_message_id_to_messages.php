<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Nettoie les doublons déjà présents (bug antérieur) : on garde le
        // message entrant le plus ancien pour chaque whatsapp_message_id.
        DB::statement(
            "DELETE FROM messages
             WHERE id IN (
                 SELECT id FROM (
                     SELECT id, ROW_NUMBER() OVER (
                         PARTITION BY whatsapp_message_id ORDER BY created_at, id
                     ) AS rn
                     FROM messages
                     WHERE direction = 'inbound' AND whatsapp_message_id IS NOT NULL
                 ) t
                 WHERE t.rn > 1
             )"
        );

        // Empêche l'enregistrement du même message entrant WhatsApp deux fois
        // (Meta réémet le webhook en cas de timeout de la réponse HTTP).
        DB::statement(
            "CREATE UNIQUE INDEX messages_inbound_wamid_unique
             ON messages (whatsapp_message_id)
             WHERE whatsapp_message_id IS NOT NULL AND direction = 'inbound'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_inbound_wamid_unique');
    }
};
