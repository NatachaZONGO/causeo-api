<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modules par défaut de chaque template : vente → commandes, services → aucun.
     * Doit rester aligné avec BusinessTemplateSeeder.
     *
     * @var array<string, list<string>>
     */
    private const TEMPLATE_MODULES = [
        'restaurant' => ['orders'],
        'boutique' => ['orders'],
        'photographe' => [],
        'salon_beaute' => [],
        'cabinet_medical' => [],
        'agence_services' => [],
        'nettoyage' => [],
        'communication' => [],
        'formation' => [],
        'autre' => [],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('business_templates', function (Blueprint $table) {
            $table->json('default_modules')->nullable();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->json('modules')->nullable();
        });

        foreach (self::TEMPLATE_MODULES as $slug => $modules) {
            DB::table('business_templates')->where('slug', $slug)->update(['default_modules' => json_encode($modules)]);
        }

        // Les businesses ne gardent pas leur template : on applique celui de même type.
        $modulesByType = DB::table('business_templates')
            ->whereNotNull('default_modules')
            ->pluck('default_modules', 'type');

        DB::table('businesses')->select(['id', 'type'])->orderBy('id')->each(function (object $business) use ($modulesByType) {
            DB::table('businesses')
                ->where('id', $business->id)
                ->update(['modules' => $modulesByType[$business->type] ?? json_encode([])]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('modules');
        });

        Schema::table('business_templates', function (Blueprint $table) {
            $table->dropColumn('default_modules');
        });
    }
};
