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
        if (Schema::hasColumn('businesses', 'whatsapp_number') && ! Schema::hasColumn('businesses', 'whatsapp_phone_number_id')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->renameColumn('whatsapp_number', 'whatsapp_phone_number_id');
            });
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'whatsapp_phone_number_id')) {
                $table->string('whatsapp_phone_number_id')->nullable();
            }

            if (! Schema::hasColumn('businesses', 'whatsapp_waba_id')) {
                $table->string('whatsapp_waba_id')->nullable()->after('whatsapp_phone_number_id');
            }

            if (! Schema::hasColumn('businesses', 'whatsapp_token')) {
                $table->text('whatsapp_token')->nullable()->after('whatsapp_waba_id');
            }

            if (! Schema::hasColumn('businesses', 'whatsapp_verified')) {
                $table->boolean('whatsapp_verified')->default(false)->after('whatsapp_token');
            }

            if (! Schema::hasColumn('businesses', 'whatsapp_connected_at')) {
                $table->timestamp('whatsapp_connected_at')->nullable()->after('whatsapp_verified');
            }

            if (! Schema::hasColumn('businesses', 'whatsapp_display_name')) {
                $table->string('whatsapp_display_name')->nullable()->after('whatsapp_connected_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_waba_id',
                'whatsapp_token',
                'whatsapp_verified',
                'whatsapp_connected_at',
                'whatsapp_display_name',
            ]);
        });

        if (Schema::hasColumn('businesses', 'whatsapp_phone_number_id')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->renameColumn('whatsapp_phone_number_id', 'whatsapp_number');
            });
        }
    }
};
