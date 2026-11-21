<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Reprise des clients existants par la migration customers : un client par numéro, toutes
 * écritures confondues, conversations et commandes rattachées, compteurs affichés ; retour
 * arrière sans perte, puis nouvelle migration identique.
 */
class CustomersMigrationTest extends TestCase
{
    use CreatesConversationSchema;

    private const MIGRATION = '2026_11_21_100000_create_customers_table.php';

    private string $bf;

    private string $ci;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-21 10:00:00');
        $this->createConversationSchema();
        $this->migration()->down();

        $owner = User::forceCreate(['name' => 'Awa', 'email' => 'awa@example.test', 'password' => 'x']);
        $this->bf = $this->business($owner->id, 'Awa Couture', 'BF');
        $this->ci = $this->business($owner->id, 'Boutique Yao', 'CI');
    }

    public function test_one_customer_per_number_with_counts_then_a_lossless_rollback(): void
    {
        $this->assertFalse(Schema::hasTable('customers'));

        // Mariam : wa_id, ancien numéro local, conversation en double, vente au comptoir.
        $a = $this->conversation($this->bf, '22670123456', 'Mariam', '2026-10-01 09:00:00', '2026-10-05 09:00:00', firstMessageAt: '2026-09-30 18:00:00');
        $b = $this->conversation($this->bf, '70123456', 'Mariam K.', '2026-10-10 09:00:00', '2026-10-12 09:00:00');
        $c = $this->conversation($this->bf, '22670123456', null, '2026-10-11 09:00:00', null);
        $sale = $this->order($this->bf, 'counter', '22670123456', 'Mariam Kaboré', '2026-11-01 09:00:00');
        // Pont WhatsApp : le numéro à la place du nom ; nom donné à l'assistant.
        $d = $this->conversation($this->bf, '22676000000', '22676000000', '2026-10-15 09:00:00', '2026-10-15 09:00:00');
        $ai = $this->order($this->bf, 'whatsapp_ai', '22676000000', 'Issa', '2026-10-15 10:00:00', $d);
        // Vente anonyme, et client venu seulement du comptoir.
        $anonymous = $this->order($this->bf, 'counter', null, 'Passant', '2026-11-01 10:00:00');
        $ali = $this->order($this->bf, 'counter', '22655555555', 'Ali', '2026-11-02 09:00:00');
        // Côte d'Ivoire : numéro local dans la conversation, international au comptoir.
        $e = $this->conversation($this->ci, '0701020304', 'Kouadio', '2026-10-20 09:00:00', '2026-10-20 09:00:00');
        $yao = $this->order($this->ci, 'counter', '2250701020304', 'K. Yao', '2026-11-03 09:00:00');

        $before = $this->fingerprint();

        $migration = $this->migration();
        $migration->up();

        $this->assertSame([
            'customers' => 4,
            'whatsapp' => 3,
            'counter_only' => 1,
            'merged_spellings' => 2,
            'duplicate_conversations' => 2,
            'conversations' => 5,
            'orders' => 4,
            'orders_without_phone' => 1,
        ], $migration->report);

        $customers = DB::table('customers')->get()->keyBy('phone');
        $this->assertEqualsCanonicalizing(['22670123456', '22676000000', '22655555555', '2250701020304'], $customers->keys()->all());

        $mariam = $customers['22670123456'];
        $this->assertSame([$this->bf, 'Mariam K.', 'Mariam Kaboré'], [$mariam->business_id, $mariam->whatsapp_name, $mariam->name]);
        $this->assertStringStartsWith('2026-09-30 18:00:00', (string) $mariam->first_seen_at);
        foreach ([$a, $b, $c] as $conversation) {
            $this->assertSame($mariam->id, DB::table('conversations')->where('id', $conversation)->value('customer_id'));
        }
        $this->assertSame($mariam->id, DB::table('orders')->where('id', $sale)->value('customer_id'));

        $issa = $customers['22676000000'];
        $this->assertSame(['Issa', null], [$issa->whatsapp_name, $issa->name]);
        $this->assertSame($issa->id, DB::table('orders')->where('id', $ai)->value('customer_id'));

        $this->assertSame(['Ali', null], [$customers['22655555555']->name, $customers['22655555555']->whatsapp_name]);
        $this->assertSame($customers['22655555555']->id, DB::table('orders')->where('id', $ali)->value('customer_id'));
        $this->assertNull(DB::table('orders')->where('id', $anonymous)->value('customer_id'));

        $kouadio = $customers['2250701020304'];
        $this->assertSame([$this->ci, 'Kouadio', 'K. Yao'], [$kouadio->business_id, $kouadio->whatsapp_name, $kouadio->name]);
        $this->assertSame($kouadio->id, DB::table('conversations')->where('id', $e)->value('customer_id'));
        $this->assertSame($kouadio->id, DB::table('orders')->where('id', $yao)->value('customer_id'));

        // Les données d'origine ne bougent pas.
        $this->assertSame($before, $this->fingerprint());

        // Retour arrière : plus de table ni de colonne, rien de perdu ; puis même résultat.
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('customers'));
        $this->assertFalse(Schema::hasColumn('conversations', 'customer_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'customer_id'));
        $this->assertSame($before, $this->fingerprint());

        $again = $this->migration();
        $again->up();
        $this->assertSame($migration->report, $again->report);
    }

    public function test_an_empty_database_migrates_with_zero_counts(): void
    {
        $migration = $this->migration();
        $migration->up();

        $this->assertSame(0, $migration->report['customers']);
        $this->assertSame(0, DB::table('customers')->count());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/'.self::MIGRATION);
    }

    private function business(int $ownerId, string $name, string $country): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $ownerId, 'name' => $name, 'country' => $country, 'modules' => '["orders"]']);

        return $id;
    }

    private function conversation(string $business, string $phone, ?string $name, string $createdAt, ?string $lastMessageAt, ?string $firstMessageAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $id, 'business_id' => $business, 'customer_phone' => $phone, 'customer_name' => $name,
            'last_message_at' => $lastMessageAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'conversation_id' => $id, 'direction' => 'inbound', 'content' => 'Bonjour',
            'created_at' => $firstMessageAt ?? $createdAt, 'updated_at' => $firstMessageAt ?? $createdAt,
        ]);

        return $id;
    }

    private function order(string $business, string $source, ?string $phone, ?string $name, string $createdAt, ?string $conversation = null): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id, 'business_id' => $business, 'conversation_id' => $conversation, 'customer_phone' => $phone,
            'customer_name' => $name, 'items' => '[]', 'total_amount' => 10000, 'status' => 'delivered', 'source' => $source,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    /** Conversations et commandes, hors colonne customer_id. */
    private function fingerprint(): string
    {
        $conversations = DB::table('conversations')->orderBy('id')->get(['id', 'business_id', 'customer_phone', 'customer_name', 'last_message_at', 'created_at', 'updated_at']);
        $orders = DB::table('orders')->orderBy('id')->get(['id', 'business_id', 'conversation_id', 'customer_phone', 'customer_name', 'total_amount', 'status', 'source', 'created_at', 'updated_at']);

        return md5(json_encode([$conversations, $orders]));
    }
}
