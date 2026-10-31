<?php

use App\Support\StatusLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liens courts des statuts WhatsApp : Meta n'envoie aucune référence quand un client répond à un
 * statut ; le client écrit donc par getcauseo.com/c/{short_code}, qui ouvre WhatsApp avec un message
 * pré-rempli contenant la référence du statut (« réf. 12 »).
 *
 * status_posts : reference (1, 2, 3… par business), short_code (« neeb12 »), clicks.
 * businesses : short_slug (« neeb », fixé au premier statut), last_status_reference (dernière
 * référence attribuée : une référence n'est jamais réutilisée, même après suppression du statut),
 * whatsapp_number (numéro du lien wa.me).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('short_slug', 12)->nullable()->unique();
            $table->unsignedInteger('last_status_reference')->default(0);
            // Numéro WhatsApp connecté, chiffres seulement (display_phone_number de Meta).
            $table->string('whatsapp_number', 20)->nullable();
        });

        Schema::table('status_posts', function (Blueprint $table) {
            $table->unsignedInteger('reference')->nullable();
            $table->string('short_code', 24)->nullable()->unique();
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['business_id', 'reference']);
        });

        // Statuts existants : numérotés par business dans l'ordre de publication.
        $businessIds = DB::table('status_posts')->distinct()->pluck('business_id');

        foreach ($businessIds as $businessId) {
            $slug = StatusLink::reserveSlug((string) $businessId);
            $posts = DB::table('status_posts')->where('business_id', $businessId)->orderBy('posted_at')->orderBy('id')->pluck('id');

            foreach ($posts as $i => $id) {
                DB::table('status_posts')->where('id', $id)->update(['reference' => $i + 1, 'short_code' => $slug.($i + 1)]);
            }

            DB::table('businesses')->where('id', $businessId)->update(['last_status_reference' => count($posts)]);
        }

        Schema::table('status_posts', function (Blueprint $table) {
            $table->unsignedInteger('reference')->nullable(false)->change();
            $table->string('short_code', 24)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('status_posts', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'reference']);
            $table->dropUnique(['short_code']);
            $table->dropColumn(['reference', 'short_code', 'clicks']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['short_slug']);
            $table->dropColumn(['short_slug', 'last_status_reference', 'whatsapp_number']);
        });
    }
};
