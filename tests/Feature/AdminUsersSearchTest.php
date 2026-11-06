<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Recherche de la page Utilisateurs : nom, e-mail ou numéro. SQLite ignore déjà la casse avec
 * LIKE : la casse sur PostgreSQL est vérifiée dans tests/Postgres/PostgresQueriesTest.php.
 */
class AdminUsersSearchTest extends TestCase
{
    use CreatesConversationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->string('whatsapp_phone')->nullable();
            $table->string('remember_token')->nullable();
        });
        (require base_path('database/migrations/2026_09_04_033251_create_personal_access_tokens_table.php'))->up();

        Sanctum::actingAs($this->user('Admin', 'admin@causeo.test', ['is_admin' => true]));
        $this->user('Awa Ouédraogo', 'awa@shop.test', ['phone' => '+226 70 12 34 56']);
        $this->user('Ali Kaboré', 'ali@example.test', ['whatsapp_phone' => '+22676999999']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function searches(): array
    {
        return [
            'nom' => ['ouédraogo', ['awa@shop.test']],
            'e-mail en majuscules' => ['SHOP.TEST', ['awa@shop.test']],
            'numéro tel qu\'enregistré' => ['+226 70 12 34 56', ['awa@shop.test']],
            'numéro sans espaces ni +' => ['22670123456', ['awa@shop.test']],
            'numéro avec + sans espaces' => ['+22670123456', ['awa@shop.test']],
            'WhatsApp, avec des espaces en plus' => ['76 99 99', ['ali@example.test']],
            'aucun résultat' => ['introuvable', []],
            'le % reste un caractère ordinaire' => ['%', []],
            'le _ reste un caractère ordinaire' => ['_', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('searches')]
    public function test_it_searches_by_name_email_or_number(string $search, array $expected): void
    {
        $this->assertSame($expected, $this->emails(['search' => $search]));
    }

    public function test_search_keeps_the_admin_filter_and_the_businesses_count(): void
    {
        $this->assertSame([], $this->emails(['search' => '70123456', 'is_admin' => 1]));
        $this->assertSame(['awa@shop.test'], $this->emails(['search' => '70123456', 'is_admin' => 0]));
        $this->assertSame(0, $this->getJson('/api/v1/admin/users?search=70123456')->assertOk()->json('data.0.businesses_count'));
    }

    /**
     * @param  array<string, string|int>  $query
     * @return list<string>
     */
    private function emails(array $query): array
    {
        return array_column($this->getJson('/api/v1/admin/users?'.http_build_query($query))->assertOk()->json('data'), 'email');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $name, string $email, array $attributes = []): User
    {
        return User::forceCreate(['name' => $name, 'email' => $email, 'password' => 'x', ...$attributes]);
    }
}
