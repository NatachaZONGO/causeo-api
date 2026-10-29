<?php

use App\Support\ActivityProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue adapté à l'activité (App\Support\ActivityProfile) et activé pour tous :
     * - businesses : profil (déduit du type), remise à « disponible » chaque matin des plats
     *   épuisés, mode de remise « sur place » (dine_in) ;
     * - products : durée (prestations), délai de préparation, suppléments payants et « épuisé »
     *   (restaurant) ;
     * - appointments : prestation du catalogue et sa durée ;
     * - le module catalog est ajouté à tous les businesses et à tous les modèles d'inscription.
     * Pas de nouvelle table : products, appointments et businesses ont déjà la RLS.
     * Les tables absentes (bases partielles des tests) sont ignorées.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('activity_profile', 30)->nullable();
            $table->boolean('catalog_daily_reset')->default(true);
            $table->boolean('dine_in_enabled')->default(false);
        });

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->unsignedInteger('preparation_minutes')->nullable();
                // [{"name": "Fromage", "price": 500}] : supplément payant, ajouté au prix du plat.
                $table->json('extras')->nullable();
                $table->timestamp('sold_out_at')->nullable();
            });
        }

        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->unsignedInteger('duration_minutes')->nullable();
            });
        }

        if (Schema::hasTable('orders')) {
            $this->setFulfillmentTypes(['delivery', 'pickup', 'shipping', 'dine_in']);
        }

        // Profil déduit du modèle choisi, et catalogue activé (toujours soumis à la formule).
        foreach (DB::table('businesses')->get(['id', 'type', 'modules']) as $business) {
            $modules = json_decode((string) $business->modules, true) ?: [];
            DB::table('businesses')->where('id', $business->id)->update([
                'activity_profile' => ActivityProfile::forType($business->type),
                'modules' => json_encode(array_values(array_unique([...$modules, 'catalog']))),
            ]);
        }

        if (Schema::hasColumn('business_templates', 'default_modules')) {
            foreach (DB::table('business_templates')->get(['id', 'default_modules']) as $template) {
                $modules = json_decode((string) $template->default_modules, true) ?: [];
                DB::table('business_templates')->where('id', $template->id)
                    ->update(['default_modules' => json_encode(array_values(array_unique([...$modules, 'catalog'])))]);
            }
        }
    }

    /**
     * Reverse the migrations. Le module catalog ajouté reste activé.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            DB::table('orders')->where('fulfillment_type', 'dine_in')->update(['fulfillment_type' => 'pickup']);
            $this->setFulfillmentTypes(['delivery', 'pickup', 'shipping']);
        }

        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_id');
                $table->dropColumn('duration_minutes');
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['duration_minutes', 'preparation_minutes', 'extras', 'sold_out_at']);
            });
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['activity_profile', 'catalog_daily_reset', 'dine_in_enabled']);
        });
    }

    /**
     * La colonne vient d'un enum Laravel : sous PostgreSQL, un varchar avec une contrainte CHECK.
     *
     * @param  list<string>  $types
     */
    private function setFulfillmentTypes(array $types): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_fulfillment_type_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_type_check CHECK (fulfillment_type IN ({$values}))");

            return;
        }

        Schema::table('orders', function (Blueprint $table) use ($types) {
            $table->enum('fulfillment_type', $types)->default('delivery')->change();
        });
    }
};
