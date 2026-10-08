<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Chiffrer les tokens WhatsApp déjà en base, au format du cast « encrypted »
     * du modèle Business (Crypt::encryptString, avec APP_KEY). Un token déjà
     * chiffré est laissé tel quel.
     */
    public function up(): void
    {
        DB::table('businesses')->whereNotNull('whatsapp_token')->select(['id', 'whatsapp_token'])->orderBy('id')
            ->each(function (object $business) {
                if ($this->isEncrypted($business->whatsapp_token)) {
                    return;
                }

                DB::table('businesses')
                    ->where('id', $business->id)
                    ->update(['whatsapp_token' => Crypt::encryptString($business->whatsapp_token)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('businesses')->whereNotNull('whatsapp_token')->select(['id', 'whatsapp_token'])->orderBy('id')
            ->each(function (object $business) {
                if (! $this->isEncrypted($business->whatsapp_token)) {
                    return;
                }

                DB::table('businesses')
                    ->where('id', $business->id)
                    ->update(['whatsapp_token' => Crypt::decryptString($business->whatsapp_token)]);
            });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
