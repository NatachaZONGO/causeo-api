<?php

namespace App\Services\Customers;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rattache les conversations et les commandes à leur client (un numéro par entreprise), en créant
 * le client au besoin. Appelé automatiquement (App\Observers\CustomerLinkObserver) : message
 * WhatsApp entrant, message envoyé depuis le téléphone du gérant, commande de l'assistant, vente au
 * comptoir.
 *
 * - whatsapp_name suit le nom de profil WhatsApp ;
 * - name (nom du commerçant) n'est rempli par une vente au comptoir que s'il est vide : un nom
 *   existant n'est jamais écrasé ;
 * - first_seen_at garde la date la plus ancienne.
 *
 * Deux messages simultanés du même numéro ne créent qu'un client (index unique, insertion ignorée).
 */
class CustomerService
{
    public function linkConversation(Conversation $conversation): ?Customer
    {
        $country = Business::query()->whereKey($conversation->business_id)->value('country');

        // Premier rattachement : date du premier message s'il précède la conversation (historique repris).
        $seenAt = $conversation->created_at;
        if ($conversation->customer_id === null && ($firstMessage = $conversation->messages()->min('created_at')) !== null) {
            $firstMessage = Carbon::parse($firstMessage);
            $seenAt = $seenAt === null || $firstMessage->lt($seenAt) ? $firstMessage : $seenAt;
        }

        $customer = $this->resolve(
            (string) $conversation->business_id,
            $country,
            $conversation->customer_phone,
            whatsappName: self::cleanName($conversation->customer_name),
            seenAt: $seenAt,
        );

        if ($customer !== null && $conversation->customer_id !== $customer->id) {
            $conversation->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }

        return $customer;
    }

    public function linkOrder(Order $order): ?Customer
    {
        $conversation = $order->conversation_id !== null ? Conversation::query()->find($order->conversation_id) : null;

        if ($conversation !== null) {
            // Commande de l'assistant : le client de la conversation. Le nom donné à l'assistant ne
            // sert qu'à défaut de nom de profil WhatsApp.
            $customer = $conversation->customer_id !== null
                ? Customer::query()->find($conversation->customer_id)
                : $this->linkConversation($conversation);

            if ($customer !== null && ($name = self::cleanName($order->customer_name)) !== null) {
                Customer::query()->whereKey($customer->id)->whereNull('whatsapp_name')->whereNull('name')->update(['whatsapp_name' => $name]);
            }
        } else {
            $merchantName = in_array($order->source, [Order::SOURCE_COUNTER, Order::SOURCE_MANUAL], true)
                ? self::cleanName($order->customer_name, Customer::NAME_MAX_LENGTH)
                : null;
            $customer = $this->resolve(
                (string) $order->business_id,
                Business::query()->whereKey($order->business_id)->value('country'),
                $order->customer_phone,
                merchantName: $merchantName,
                seenAt: $order->created_at,
            );
        }

        if ($customer !== null && $order->customer_id !== $customer->id) {
            $order->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }

        return $customer;
    }

    /**
     * Client de ce numéro, créé s'il n'existe pas. Null sans numéro.
     */
    public function resolve(string $businessId, ?string $country, ?string $phone, ?string $whatsappName = null, ?string $merchantName = null, ?CarbonInterface $seenAt = null): ?Customer
    {
        $phone = PhoneNumber::canonical($phone, $country);
        if ($phone === '') {
            return null;
        }

        $seenAt ??= now();
        $now = now();

        DB::table('customers')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'phone' => $phone,
            'whatsapp_name' => $whatsappName,
            'name' => $merchantName,
            'first_seen_at' => $seenAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customer = Customer::query()->where('business_id', $businessId)->where('phone', $phone)->firstOrFail();
        $changed = false;

        if ($whatsappName !== null && $customer->whatsapp_name !== $whatsappName) {
            $changed = Customer::query()->whereKey($customer->id)->update(['whatsapp_name' => $whatsappName, 'updated_at' => $now]) > 0 || $changed;
        }
        if ($merchantName !== null && $customer->name === null) {
            // Jamais d'écrasement, même si un autre enregistrement vient de remplir le nom.
            $changed = Customer::query()->whereKey($customer->id)->whereNull('name')->update(['name' => $merchantName, 'updated_at' => $now]) > 0 || $changed;
        }
        if ($customer->first_seen_at === null || $customer->first_seen_at->gt($seenAt)) {
            $changed = Customer::query()->whereKey($customer->id)
                ->where(fn ($query) => $query->whereNull('first_seen_at')->orWhere('first_seen_at', '>', $seenAt))
                ->update(['first_seen_at' => $seenAt]) > 0 || $changed;
        }

        return $changed ? $customer->refresh() : $customer;
    }

    /**
     * Nom lisible, ou null s'il est vide ou n'est qu'un numéro (le pont WhatsApp met le numéro à
     * défaut de nom de profil).
     */
    public static function cleanName(?string $name, int $max = 255): ?string
    {
        $name = trim(preg_replace('/[^\P{C}\n]+/u', '', (string) $name) ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name === '' || preg_match('/^[\d\s+().\-]+$/', $name) === 1 ? null : mb_substr($name, 0, $max);
    }
}
