<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'essai devient « TRIAL_DAYS jours ou TRIAL_UNITS unités » : début et nombre
     * d'unités sont figés dans l'abonnement.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_started_at')->nullable()->after('status');
            $table->unsignedInteger('trial_unit_limit')->nullable()->after('trial_ends_at'); // null : limite de la formule
        });

        // Essais en cours (ancienne offre : 30 jours, limite du Pro) : ils gardent leurs
        // conditions ; seul le début, jusqu'ici déduit de la fin, est enregistré.
        DB::table('subscriptions')
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->orderBy('id')
            ->get(['id', 'trial_ends_at'])
            ->each(fn ($subscription) => DB::table('subscriptions')->where('id', $subscription->id)->update([
                'trial_started_at' => CarbonImmutable::parse($subscription->trial_ends_at)->subDays(30),
            ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['trial_started_at', 'trial_unit_limit']);
        });
    }
};
