<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('shipping_enabled')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('fulfillment_type', ['delivery', 'pickup', 'shipping'])->default('delivery');
            $table->text('pickup_time')->nullable();

            $table->index(['business_id', 'fulfillment_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'fulfillment_type']);
            $table->dropColumn(['fulfillment_type', 'pickup_time']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['delivery_enabled', 'pickup_enabled', 'shipping_enabled']);
        });
    }
};
