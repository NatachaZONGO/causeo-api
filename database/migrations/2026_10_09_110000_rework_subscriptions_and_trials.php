<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // L'ancienne table n'a jamais été utilisée (vide en production) : on la recrée.
        // Par sécurité, on refuse de supprimer des abonnements existants.
        if (Schema::hasTable('subscriptions') && DB::table('subscriptions')->exists()) {
            throw new RuntimeException('La table subscriptions contient des lignes : migration interrompue, à reprendre à la main.');
        }

        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans');
            $table->enum('status', ['trialing', 'active', 'grace', 'expired', 'cancelled']);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable(); // null : sans échéance (Interne)
            $table->timestamp('ended_at')->nullable(); // passage en Gratuit
            $table->timestamps();

            $table->index('status');
        });

        // Un seul essai par utilisateur et par numéro WhatsApp, même si le business est supprimé.
        Schema::create('trial_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('whatsapp_number');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE trial_usages ENABLE ROW LEVEL SECURITY');
        }

        // Les businesses existants passent sur la formule Interne (sans limite ni échéance).
        $internalPlanId = DB::table('plans')->where('slug', 'internal')->value('id');

        if ($internalPlanId === null) {
            // Le seeder des formules tourne après les migrations : on crée la formule Interne
            // ici, le seeder complètera ses autres champs.
            $internalPlanId = (string) Str::uuid();
            DB::table('plans')->insert([
                'id' => $internalPlanId,
                'slug' => 'internal',
                'name' => 'Interne',
                'modules' => json_encode(['orders', 'appointments']),
                'is_public' => false,
                'sort_order' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('businesses')->select('id')->orderBy('id')->each(function (object $business) use ($internalPlanId) {
            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $business->id,
                'plan_id' => $internalPlanId,
                'status' => 'active',
                'current_period_start' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('businesses')->update(['plan' => 'internal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trial_usages');
        Schema::dropIfExists('subscriptions');

        DB::table('businesses')->update(['plan' => 'free']);

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->integer('price');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_provider')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }
};
