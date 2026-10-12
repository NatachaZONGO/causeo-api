<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retours des gérants (bugs, suggestions, questions), traités par les admins.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['bug', 'suggestion', 'question', 'autre']);
            $table->text('message');
            $table->string('page', 2048)->nullable(); // URL du dashboard où se trouvait le gérant
            $table->json('context')->nullable(); // navigateur, taille d'écran, version du dashboard
            $table->string('screenshot_path')->nullable(); // bucket privé documents
            $table->enum('status', ['nouveau', 'en_cours', 'resolu', 'ferme'])->default('nouveau');
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Un retour envoyé sans business notifie quand même les admins.
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('business_id')->nullable()->change();
        });

        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing', 'feedback']);

        // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE feedback ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')->where('type', 'feedback')->delete();
        DB::table('notifications')->whereNull('business_id')->delete();
        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing']);

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('business_id')->nullable(false)->change();
        });

        Schema::dropIfExists('feedback');
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
