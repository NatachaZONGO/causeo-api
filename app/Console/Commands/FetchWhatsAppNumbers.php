<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Récupérer une fois, auprès de Meta, le numéro WhatsApp des businesses connectés avant qu'il ne
 * soit enregistré à la connexion (businesses.whatsapp_number, lien wa.me des statuts). Rien n'est
 * modifié pour un business qui a déjà son numéro. Les numéros ne sont jamais affichés.
 */
class FetchWhatsAppNumbers extends Command
{
    protected $signature = 'causeo:whatsapp-numbers';

    protected $description = 'Enregistre le numéro WhatsApp (display_phone_number) des businesses connectés qui ne l\'ont pas encore';

    public function handle(): int
    {
        $businesses = Business::query()
            ->where('whatsapp_verified', true)
            ->whereNotNull('whatsapp_phone_number_id')
            ->whereNull('whatsapp_number')
            ->get();

        $saved = 0;

        foreach ($businesses as $business) {
            $token = $business->whatsapp_token ?: (string) config('services.whatsapp.token');

            try {
                $number = Http::withToken($token)
                    ->withOptions(['verify' => config('services.curl_ca_bundle', true)])
                    ->timeout(15)
                    ->get(rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com/v21.0/'), '/').'/'.$business->whatsapp_phone_number_id, ['fields' => 'display_phone_number'])
                    ->throw()
                    ->json('display_phone_number');
            } catch (Throwable $e) {
                $this->warn("{$business->id} : numéro non récupéré (".$e::class.').');

                continue;
            }

            $digits = preg_replace('/\D/', '', (string) $number);

            if ($digits === '') {
                $this->warn("{$business->id} : Meta ne renvoie pas de numéro.");

                continue;
            }

            $business->forceFill(['whatsapp_number' => $digits])->save();
            $saved++;
        }

        $this->info("{$saved} numéro(s) enregistré(s) sur {$businesses->count()} business(es) sans numéro.");

        return self::SUCCESS;
    }
}
