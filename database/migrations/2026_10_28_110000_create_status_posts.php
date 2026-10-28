<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statuts du jour : l'API WhatsApp ne donne pas les statuts publiés depuis l'application, le
     * gérant enregistre donc ce qu'il y met (un article du catalogue, ou une image et sa légende),
     * pour que l'assistant sache à quoi répond un client. Un statut expire 24 h après sa publication.
     * Notification « status » : un client répond à un statut alors qu'aucun n'est enregistré.
     */
    public function up(): void
    {
        Schema::create('status_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            // Image importée : média inactif (hors du catalogue illustré), envoyable au client.
            $table->foreignUuid('media_id')->nullable()->constrained('business_media')->nullOnDelete();
            $table->text('caption')->nullable();
            $table->timestamp('posted_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['business_id', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
            DB::statement('ALTER TABLE status_posts ENABLE ROW LEVEL SECURITY');
        }

        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing', 'feedback', 'stock', 'status']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')->where('type', 'status')->delete();
        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing', 'feedback', 'stock']);

        Schema::dropIfExists('status_posts');
    }

    /**
     * @param  list<string>  $types
     */
    private function setNotificationTypes(array $types): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$values}))");

            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($types) {
            $table->enum('type', $types)->change();
        });
    }
};
