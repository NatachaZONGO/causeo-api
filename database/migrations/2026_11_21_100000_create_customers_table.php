<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Clients (phase 2) : une seule liste par entreprise, un client = un numéro de téléphone
     * normalisé (chiffres avec l'indicatif, App\Support\PhoneNumber). On y retrouve les personnes
     * qui ont écrit sur WhatsApp (conversations) et celles enregistrées lors d'une vente au comptoir
     * (orders), reliées par conversations.customer_id et orders.customer_id.
     *
     * - whatsapp_name : nom de profil WhatsApp (ou, à défaut, nom donné à l'assistant), mis à jour
     *   automatiquement ;
     * - name : nom choisi par le commerçant (fiche client ou vente au comptoir), jamais écrasé ;
     * - note : note libre du commerçant.
     *
     * Les montants, le dernier message et l'origine ne sont pas stockés : ils se calculent à la
     * lecture depuis orders et conversations (App\Services\Customers\CustomerListQuery).
     *
     * Reprise : un client par numéro, toutes écritures confondues (« +226 70… », « 70… » au
     * Burkina) ; les conversations et commandes existantes lui sont rattachées. Une vente sans
     * numéro reste anonyme. Rien n'est modifié ni supprimé dans les données existantes.
     */

    /**
     * Résultat de la reprise, affiché pendant la migration (sans nom ni numéro de client).
     *
     * @var array{customers: int, whatsapp: int, counter_only: int, merged_spellings: int, duplicate_conversations: int, conversations: int, orders: int, orders_without_phone: int}
     */
    public array $report = [
        'customers' => 0,
        'whatsapp' => 0,
        'counter_only' => 0,
        'merged_spellings' => 0,
        'duplicate_conversations' => 0,
        'conversations' => 0,
        'orders' => 0,
        'orders_without_phone' => 0,
    ];

    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            // Chiffres avec l'indicatif, sans « + » (E.164 : 15 chiffres au plus ; un identifiant
            // WhatsApp non reconnu reste tel quel).
            $table->string('phone', 32);
            $table->string('whatsapp_name')->nullable();
            $table->string('name', 100)->nullable();
            $table->text('note')->nullable();
            // Premier message ou première commande.
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'phone']);
            $table->index(['business_id', 'first_seen_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->index('customer_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['business_id', 'customer_id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
            DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
        }

        DB::table('businesses')->select(['id', 'country'])->orderBy('id')->chunk(100, function ($businesses) {
            foreach ($businesses as $business) {
                $this->backfill((string) $business->id, $business->country);
            }
        });

        $this->announce();
    }

    /**
     * Retour arrière : les conversations et les commandes gardent leur numéro et leur nom ; seuls
     * les noms modifiés et les notes saisis depuis sont perdus.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'customer_id']);
            $table->dropIndex(['customer_id']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }

    private function backfill(string $businessId, ?string $country): void
    {
        $conversations = DB::table('conversations')
            ->where('business_id', $businessId)
            ->orderBy('created_at')
            ->get(['id', 'customer_phone', 'customer_name', 'created_at', 'last_message_at']);

        $firstMessages = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.business_id', $businessId)
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id AS id, MIN(messages.created_at) AS first_at')
            ->pluck('first_at', 'id');

        $orders = DB::table('orders')
            ->where('business_id', $businessId)
            ->orderBy('created_at')
            ->get(['id', 'conversation_id', 'customer_phone', 'customer_name', 'source', 'created_at']);

        /** @var array<string, array{spellings: array<string, true>, whatsapp_name: ?string, whatsapp_at: ?string, fallback_name: ?string, name: ?string, name_at: ?string, first: ?string, conversations: list<string>, orders: list<string>}> $clients */
        $clients = [];
        $conversationPhones = [];

        foreach ($conversations as $conversation) {
            $phone = PhoneNumber::canonical($conversation->customer_phone, $country);
            if ($phone === '') {
                continue;
            }
            $conversationPhones[$conversation->id] = $phone;
            $client = &$this->client($clients, $phone);
            $client['spellings'][trim((string) $conversation->customer_phone)] = true;
            $client['conversations'][] = (string) $conversation->id;
            $client['first'] = self::earliest($client['first'], $conversation->created_at, $firstMessages[$conversation->id] ?? null);

            $name = self::name($conversation->customer_name, 255);
            $at = (string) ($conversation->last_message_at ?? $conversation->created_at);
            if ($name !== null && ($client['whatsapp_at'] === null || $at >= $client['whatsapp_at'])) {
                [$client['whatsapp_name'], $client['whatsapp_at']] = [$name, $at];
            }
            unset($client);
        }

        foreach ($orders as $order) {
            $phone = $conversationPhones[$order->conversation_id ?? ''] ?? PhoneNumber::canonical($order->customer_phone, $country);
            if ($phone === '') {
                $this->report['orders_without_phone']++;

                continue;
            }
            $client = &$this->client($clients, $phone);
            if ($order->conversation_id === null || ! isset($conversationPhones[$order->conversation_id])) {
                $client['spellings'][trim((string) $order->customer_phone)] = true;
            }
            $client['orders'][] = (string) $order->id;
            $client['first'] = self::earliest($client['first'], $order->created_at);

            // Nom saisi par le commerçant (comptoir, saisie manuelle) : le plus récent. Le nom donné
            // à l'assistant ne sert qu'à défaut de nom de profil WhatsApp.
            if (in_array($order->source, ['counter', 'manual'], true)) {
                $name = self::name($order->customer_name, 100);
                if ($name !== null && ($client['name_at'] === null || (string) $order->created_at >= $client['name_at'])) {
                    [$client['name'], $client['name_at']] = [$name, (string) $order->created_at];
                }
            } elseif ($client['fallback_name'] === null) {
                $client['fallback_name'] = self::name($order->customer_name, 255);
            }
            unset($client);
        }

        $now = now();
        $rows = [];
        foreach ($clients as $phone => $client) {
            $id = (string) Str::uuid();
            $clients[$phone]['id'] = $id;
            $rows[] = [
                'id' => $id,
                'business_id' => $businessId,
                'phone' => (string) $phone,
                'whatsapp_name' => $client['whatsapp_name'] ?? $client['fallback_name'],
                'name' => $client['name'],
                'first_seen_at' => $client['first'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->report['customers']++;
            $this->report[$client['conversations'] !== [] ? 'whatsapp' : 'counter_only']++;
            $this->report['merged_spellings'] += max(0, count($client['spellings']) - 1);
            $this->report['duplicate_conversations'] += max(0, count($client['conversations']) - 1);
            $this->report['conversations'] += count($client['conversations']);
            $this->report['orders'] += count($client['orders']);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        foreach ($clients as $client) {
            foreach (array_chunk($client['conversations'], 500) as $ids) {
                DB::table('conversations')->whereIn('id', $ids)->update(['customer_id' => $client['id']]);
            }
            foreach (array_chunk($client['orders'], 500) as $ids) {
                DB::table('orders')->whereIn('id', $ids)->update(['customer_id' => $client['id']]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $clients
     * @return array<string, mixed>
     */
    private function &client(array &$clients, string $phone): array
    {
        $clients[$phone] ??= [
            'spellings' => [], 'whatsapp_name' => null, 'whatsapp_at' => null, 'fallback_name' => null,
            'name' => null, 'name_at' => null, 'first' => null, 'conversations' => [], 'orders' => [],
        ];

        return $clients[$phone];
    }

    /** Nom lisible, ou null s'il est vide ou n'est qu'un numéro (le pont WhatsApp met le numéro à défaut de nom). */
    private static function name(?string $name, int $max): ?string
    {
        $name = trim(preg_replace('/[^\P{C}\n]+/u', '', (string) $name) ?? '');

        return $name === '' || preg_match('/^[\d\s+().\-]+$/', $name) === 1 ? null : mb_substr($name, 0, $max);
    }

    private static function earliest(?string ...$dates): ?string
    {
        $dates = array_filter(array_map(fn ($date) => $date === null ? null : (string) $date, $dates), fn ($date) => $date !== null && $date !== '');

        return $dates === [] ? null : min($dates);
    }

    /** Résultat affiché pendant php artisan migrate (et dans les logs), sans donnée de client. */
    private function announce(): void
    {
        $r = $this->report;
        $lines = [
            "Clients : {$r['customers']} créés ({$r['whatsapp']} venus de WhatsApp, {$r['counter_only']} du comptoir seulement).",
            "Doublons fusionnés : {$r['merged_spellings']} écritures différentes d'un même numéro, {$r['duplicate_conversations']} conversations en double du même numéro.",
            "Rattachés : {$r['conversations']} conversations, {$r['orders']} commandes ; {$r['orders_without_phone']} ventes sans numéro restent anonymes.",
        ];

        Log::info('Migration customers : reprise des clients', $r);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $output = new ConsoleOutput;
            foreach ($lines as $line) {
                $output->writeln("  {$line}");
            }
        }
    }
};
