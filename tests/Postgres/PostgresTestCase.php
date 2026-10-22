<?php

namespace Tests\Postgres;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests sur un vrai PostgreSQL (avec pgvector), avec les vraies migrations : ce que
 * SQLite accepte et PostgreSQL refuse (MAX(uuid), chemins JSON, pgvector, contraintes
 * CHECK…). Ignorés sauf si CAUSEO_PGSQL_TESTS=true, pour ne jamais vider une base
 * configurée par erreur. En CI : job « Tests (PostgreSQL) » de .github/workflows/deploy.yml.
 */
abstract class PostgresTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('CAUSEO_PGSQL_TESTS') !== 'true') {
            $this->markTestSkipped('Tests PostgreSQL : CAUSEO_PGSQL_TESTS=true et DB_CONNECTION=pgsql requis.');
        }

        parent::setUp();

        $this->assertSame('pgsql', DB::getDriverName(), 'Ces tests doivent tourner sur PostgreSQL.');
    }

    protected function user(string $name = 'Joyce', bool $admin = false): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $name, 'email' => Str::lower($name).'-'.Str::random(6).'@example.test', 'password' => 'x',
            'is_admin' => $admin, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function business(int $userId, string $name = 'Joyce Boutique'): string
    {
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $userId, 'name' => $name, 'type' => 'boutique',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function conversation(string $businessId, string $customer, ?string $lastReadAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $id, 'business_id' => $businessId, 'customer_phone' => '2267'.random_int(1000000, 9999999),
            'customer_name' => $customer, 'last_message_at' => now(), 'last_read_at' => $lastReadAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    protected function message(string $conversationId, string $direction, string $content, string $at, ?string $id = null, ?array $metadata = null, string $sender = ''): string
    {
        $id ??= (string) Str::uuid();
        DB::table('messages')->insert([
            'id' => $id, 'conversation_id' => $conversationId, 'direction' => $direction,
            'sender_type' => $sender ?: ($direction === 'inbound' ? 'customer' : 'ai'),
            'content' => $content, 'status' => 'sent',
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    protected function escalation(string $conversationId, string $businessId, string $messageId, string $question, string $status = 'pending', ?string $at = null): string
    {
        $id = (string) Str::uuid();
        $at ??= now()->toDateTimeString();
        DB::table('escalations')->insert([
            'id' => $id, 'conversation_id' => $conversationId, 'message_id' => $messageId, 'business_id' => $businessId,
            'customer_question' => $question, 'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    /**
     * Vecteur pgvector de 1536 dimensions, tout à zéro sauf $axis.
     */
    protected static function vector(int $axis, float $value = 1.0): string
    {
        $values = array_fill(0, 1536, 0);
        $values[$axis] = $value;

        return '['.implode(',', $values).']';
    }
}
