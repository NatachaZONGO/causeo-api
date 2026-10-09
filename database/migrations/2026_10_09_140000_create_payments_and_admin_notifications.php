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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans');
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('months')->default(1);
            $table->unsignedInteger('amount'); // calculé par le serveur
            $table->char('currency', 3)->default('XOF');
            $table->enum('method', ['orange_money', 'moov_money']);
            $table->string('reference'); // référence de transaction, normalisée en majuscules
            $table->string('payer_phone')->nullable();
            $table->string('proof_path')->nullable(); // capture d'écran (bucket privé documents)
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('period_start')->nullable(); // période couverte, une fois validé
            $table->timestamp('period_end')->nullable();
            $table->timestamps();

            // Une transaction ne peut être déclarée qu'une fois par moyen de paiement.
            $table->unique(['method', 'reference']);
            $table->index('status');
            $table->index('business_id');
        });

        // Destinataire d'une notification : le gérant du business, ou les admins Causeo.
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('audience')->default('owner');
            $table->index(['audience', 'read_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')->where('audience', 'admin')->delete();

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['audience', 'read_at']);
            $table->dropColumn('audience');
        });

        Schema::dropIfExists('payments');
    }
};
