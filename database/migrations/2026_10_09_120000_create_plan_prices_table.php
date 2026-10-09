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
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3); // code ISO 4217 : XOF, EUR, USD
            $table->unsignedInteger('amount'); // en unités de la devise (pas de centimes)
            $table->unsignedInteger('period_days');
            $table->timestamps();

            $table->unique(['plan_id', 'currency']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE plan_prices ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
