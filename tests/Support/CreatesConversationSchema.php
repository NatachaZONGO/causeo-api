<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les migrations complètes dépendent de pgvector : on crée un schéma minimal sur
 * SQLite pour les tables de base, puis les vraies migrations des notifications,
 * commandes, modes de remise, modules et rendez-vous.
 */
trait CreatesConversationSchema
{
    protected function createConversationSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('whatsapp_token')->nullable();
            $table->string('whatsapp_phone_number_id')->nullable();
            $table->string('whatsapp_waba_id')->nullable();
            $table->boolean('whatsapp_verified')->default(false);
            $table->timestamp('whatsapp_connected_at')->nullable();
            $table->string('whatsapp_display_name')->nullable();
            $table->integer('monthly_message_count')->default(0);
            $table->string('plan')->default('free');
            $table->string('country')->default('BF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('direction');
            $table->string('sender_type')->nullable();
            $table->text('content');
            $table->string('status')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        // Même index unique qu'en production (un message entrant WhatsApp n'est enregistré qu'une fois).
        DB::statement("CREATE UNIQUE INDEX messages_inbound_wamid_unique ON messages (whatsapp_message_id) WHERE whatsapp_message_id IS NOT NULL AND direction = 'inbound'");
        Schema::create('business_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
            $table->string('type');
        });

        foreach ([
            '2026_10_07_100000_create_notifications_table.php',
            '2026_10_07_110000_create_orders_table.php',
            '2026_10_07_120000_add_fulfillment_options.php',
            '2026_10_08_100000_add_modules_to_businesses.php',
            '2026_10_08_110000_add_appointments_module.php',
            '2026_10_08_120000_add_whatsapp_notification_type.php',
            '2026_10_08_130000_encrypt_whatsapp_tokens.php',
            '2026_09_06_003335_create_subscriptions_table.php',
            '2026_10_09_100000_create_plans_table.php',
            '2026_10_09_120000_create_plan_prices_table.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }

        // Comme au déploiement : formules, puis refonte des abonnements.
        (new PlanSeeder())->run();
        (require base_path('database/migrations/2026_10_09_110000_rework_subscriptions_and_trials.php'))->up();
        (require base_path('database/migrations/2026_10_09_130000_add_billing_notification_type.php'))->up();
        (require base_path('database/migrations/2026_10_09_140000_create_payments_and_admin_notifications.php'))->up();
    }

    /**
     * Donner une formule à un business de test (Interne par défaut : tous les modules,
     * sans limite). Sans abonnement, un business est en Gratuit.
     */
    protected function assignPlan(string $businessId, string $plan = Plan::INTERNAL): void
    {
        app(BillingService::class)->assignPlan(Business::findOrFail($businessId), Plan::bySlug($plan));
    }
}
