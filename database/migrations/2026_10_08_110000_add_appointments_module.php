<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Templates qui reçoivent le module Rendez-vous par défaut (en plus de leurs
     * modules actuels). Doit rester aligné avec BusinessTemplateSeeder ; les
     * templates garage et auto_ecole sont créés par le seeder.
     */
    private const TEMPLATE_SLUGS = ['restaurant', 'photographe', 'salon_beaute', 'cabinet_medical', 'garage', 'auto_ecole'];

    /**
     * Types d'entreprise existants qui reçoivent le module (anciens types sans template inclus).
     */
    private const BUSINESS_TYPES = ['restaurant', 'photographe', 'salon_beaute', 'cabinet_medical', 'garage', 'auto_ecole', 'salon', 'clinic'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->string('service');
            $table->date('requested_date');
            $table->string('requested_time');
            $table->text('location')->nullable();
            $table->unsignedInteger('participants')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['requested', 'confirmed', 'declined', 'cancelled', 'completed'])->default('requested');
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'requested_date']);
            $table->index('conversation_id');
        });

        $this->setNotificationTypes(['escalation', 'order', 'appointment']);

        // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
        // Laravel se connecte avec le rôle propriétaire de la table, qui n'est pas concerné.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointments ENABLE ROW LEVEL SECURITY');
        }

        foreach (DB::table('business_templates')->whereIn('slug', self::TEMPLATE_SLUGS)->get(['id', 'default_modules']) as $template) {
            DB::table('business_templates')
                ->where('id', $template->id)
                ->update(['default_modules' => $this->withAppointments($template->default_modules)]);
        }

        // On ajoute le module sans toucher aux autres (un module activé à la main est conservé).
        DB::table('businesses')->whereIn('type', self::BUSINESS_TYPES)->select(['id', 'modules'])->orderBy('id')
            ->each(function (object $business) {
                DB::table('businesses')
                    ->where('id', $business->id)
                    ->update(['modules' => $this->withAppointments($business->modules)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('businesses')->select(['id', 'modules'])->orderBy('id')->each(function (object $business) {
            $modules = array_values(array_diff(json_decode((string) $business->modules, true) ?: [], ['appointments']));
            DB::table('businesses')->where('id', $business->id)->update(['modules' => json_encode($modules)]);
        });

        DB::table('notifications')->where('type', 'appointment')->delete();
        $this->setNotificationTypes(['escalation', 'order']);

        Schema::dropIfExists('appointments');
    }

    private function withAppointments(?string $modules): string
    {
        $list = json_decode((string) $modules, true) ?: [];

        return json_encode(array_values(array_unique([...$list, 'appointments'])));
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
