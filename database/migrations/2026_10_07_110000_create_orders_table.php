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
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->json('items'); // [{name, options, quantity, unit_price}]
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('delivery_city')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('payment_method')->nullable();
            $table->enum('status', ['new', 'confirmed', 'paid', 'delivered', 'cancelled'])->default('new');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('conversation_id');
        });

        // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
        // Laravel se connecte avec le rôle propriétaire de la table, qui n'est pas concerné.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
