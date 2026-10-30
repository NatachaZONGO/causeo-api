<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

/**
 * Export des contacts clients en vCard 3.0 : un contact par numéro, « nouveaux » depuis le
 * dernier export ou tous, pour le gérant propriétaire seulement.
 */
class ContactExportTest extends TestCase
{
    use CreatesConversationSchema;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-30 10:00:00');
        $this->createConversationSchema();
        (require base_path('database/migrations/2026_10_30_100000_add_contacts_exported_at_to_businesses.php'))->up();

        $this->owner = User::forceCreate(['name' => 'Gérante', 'email' => 'gerante@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert(['id' => $id, 'user_id' => $this->owner->id, 'name' => 'NEEB-RATA MARKET', 'modules' => '[]']);
        // Disponible dans toutes les formules, la gratuite comprise.
        $this->assignPlan($id, Plan::FREE);
        $this->business = Business::findOrFail($id);
        Sanctum::actingAs($this->owner);
    }

    public function test_the_vcard_has_one_contact_per_customer_with_name_phone_and_first_message_date(): void
    {
        $this->client('22670123456', 'Awa Ouédraogo', '2026-10-02 08:30:00', firstMessageAt: '2026-10-01 18:00:00');
        $this->client('22676543210', null, '2026-10-05 09:00:00');
        // Deuxième conversation du même numéro : toujours un seul contact.
        $this->client('22670123456', 'Awa', '2026-10-20 09:00:00');

        $response = $this->exportAs('all');

        $response->assertOk();
        $this->assertStringStartsWith('text/vcard', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=utf-8', strtolower($response->headers->get('Content-Type')));
        $this->assertSame('attachment; filename="clients-neeb-rata-market-2026-10-30.vcf"', $response->headers->get('Content-Disposition'));

        $body = $response->getContent();
        $this->assertSame(2, substr_count($body, 'BEGIN:VCARD'));
        $this->assertStringContainsString(
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Awa Ouédraogo · NEEB-RATA MARKET\r\nN:Awa Ouédraogo · NEEB-RATA MARKET;;;;\r\n"
            ."TEL;TYPE=CELL:+22670123456\r\nNOTE:Client WhatsApp depuis le 01/10/2026\r\nEND:VCARD\r\n",
            $body,
        );
        // Sans nom : « Client {4 derniers chiffres} », date de la conversation à défaut de message.
        $this->assertStringContainsString("FN:Client 3210 · NEEB-RATA MARKET\r\n", $body);
        $this->assertStringContainsString("TEL;TYPE=CELL:+22676543210\r\nNOTE:Client WhatsApp depuis le 05/10/2026\r\n", $body);
        $this->assertStringNotContainsString('FN:Awa · ', $body);
    }

    public function test_special_characters_are_escaped_long_names_are_truncated_and_lines_are_folded(): void
    {
        $this->client('70112233', "Dupont; Marie, \\ la\nboss", '2026-10-10 10:00:00');
        $this->client('22670998877', str_repeat('Kaboré ', 20), '2026-10-11 10:00:00');

        $body = $this->exportAs('all')->assertOk()->getContent();

        $this->assertStringContainsString('FN:Dupont\; Marie\, \\\\ la\nboss · NEEB-RATA MARKET', $body);
        // Numéro local à 8 chiffres : indicatif du Burkina ajouté.
        $this->assertStringContainsString('TEL;TYPE=CELL:+22670112233', $body);

        $unfolded = str_replace("\r\n ", '', $body);
        $this->assertStringContainsString('FN:'.Str::limit(str_repeat('Kaboré ', 20), 50, '…').' · NEEB-RATA MARKET', $unfolded);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'Une ligne repliée coupe un caractère UTF-8.');
        }
    }

    public function test_whatsapp_numbers_keep_their_country_code_and_only_local_numbers_get_the_business_one(): void
    {
        $this->client('2250701020304', 'Ivoirienne', '2026-10-01 10:00:00');
        $this->client('33612345678', 'Française', '2026-10-02 10:00:00');
        $this->client('12025550123', 'Américain', '2026-10-03 10:00:00');
        $this->client('70123456', 'Burkinabè', '2026-10-04 10:00:00');

        $body = $this->exportAs('all')->assertOk()->getContent();

        foreach (['+2250701020304', '+33612345678', '+12025550123', '+22670123456'] as $phone) {
            $this->assertStringContainsString("TEL;TYPE=CELL:{$phone}\r\n", $body);
        }
        $this->assertStringNotContainsString('+226225', $body);
        $this->assertStringNotContainsString('+22633', $body);
    }

    public function test_a_local_number_gets_the_country_code_of_the_business_not_226(): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['country' => 'CI']);
        $this->client('0701020304', 'Cliente', '2026-10-01 10:00:00');

        $body = $this->exportAs('all')->assertOk()->getContent();

        $this->assertStringContainsString("TEL;TYPE=CELL:+2250701020304\r\n", $body);
    }

    public function test_scope_new_exports_new_customers_once_then_answers_204(): void
    {
        $this->client('22670000001', 'Awa', '2026-10-01 10:00:00');
        $this->client('22670000002', 'Issa', '2026-10-02 10:00:00');

        $this->getJson($this->url('summary'))->assertExactJson(['total' => 2, 'new_since_last_export' => 2, 'last_exported_at' => null]);

        $first = $this->exportAs('new')->assertOk()->getContent();
        $this->assertSame(2, substr_count($first, 'BEGIN:VCARD'));
        $this->assertSame('2026-10-30 10:00:00', $this->business->fresh()->contacts_exported_at->format('Y-m-d H:i:s'));

        $this->exportAs('new')->assertNoContent();
        $this->getJson($this->url('summary'))->assertExactJson([
            'total' => 2, 'new_since_last_export' => 0, 'last_exported_at' => '2026-10-30T10:00:00+00:00',
        ]);

        // Un client arrive : seul lui part au prochain export.
        $this->travel(2)->hours();
        $this->client('22670000003', 'Fatim', now()->toDateTimeString());
        $this->getJson($this->url('summary'))->assertJson(['total' => 3, 'new_since_last_export' => 1]);

        $next = $this->exportAs('new')->assertOk()->getContent();
        $this->assertSame(1, substr_count($next, 'BEGIN:VCARD'));
        $this->assertStringContainsString('FN:Fatim · NEEB-RATA MARKET', $next);
    }

    public function test_scope_all_exports_everyone_without_moving_the_export_date(): void
    {
        $this->client('22670000001', 'Awa', '2026-10-01 10:00:00');
        $this->exportAs('new')->assertOk();
        $exportedAt = $this->business->fresh()->contacts_exported_at;

        $this->travel(1)->day();
        $body = $this->exportAs('all')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, 'BEGIN:VCARD'));
        $this->assertTrue($this->business->fresh()->contacts_exported_at->equalTo($exportedAt));
    }

    public function test_no_customers_gives_204_and_a_bad_scope_is_rejected(): void
    {
        $this->exportAs('all')->assertNoContent();
        $this->exportAs('new')->assertNoContent();
        $this->exportAs('everyone')->assertUnprocessable();
        $this->assertNull($this->business->fresh()->contacts_exported_at);
    }

    public function test_another_user_or_an_admin_gets_403(): void
    {
        $this->client('22670000001', 'Awa', '2026-10-01 10:00:00');

        Sanctum::actingAs(User::forceCreate(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'x']));
        $this->getJson($this->url('summary'))->assertForbidden();
        $this->getJson($this->url('export?scope=all'))->assertForbidden();

        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]));
        $this->getJson($this->url('export?scope=new'))->assertForbidden();

        $this->assertNull($this->business->fresh()->contacts_exported_at);
    }

    public function test_customer_names_and_numbers_are_never_logged(): void
    {
        Log::spy();
        $this->client('22670123456', 'Awa Ouédraogo', '2026-10-01 10:00:00');

        $this->exportAs('new')->assertOk();
        $this->getJson($this->url('summary'))->assertOk();

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($message) => str_contains((string) $message, '70123456') || str_contains((string) $message, 'Awa'))]);
        }
    }

    private function exportAs(string $scope)
    {
        return $this->get($this->url("export?scope={$scope}"), ['Accept' => 'application/json']);
    }

    private function url(string $path): string
    {
        return "/api/v1/businesses/{$this->business->id}/contacts/{$path}";
    }

    private function client(string $phone, ?string $name, string $createdAt, ?string $firstMessageAt = null): void
    {
        $conversation = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversation, 'business_id' => $this->business->id, 'customer_phone' => $phone,
            'customer_name' => $name, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        if ($firstMessageAt !== null) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'direction' => 'inbound',
                'content' => 'Bonjour', 'created_at' => $firstMessageAt, 'updated_at' => $firstMessageAt,
            ]);
        }
    }
}
