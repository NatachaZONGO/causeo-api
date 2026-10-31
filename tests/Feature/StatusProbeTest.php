<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCustomer;
use App\Models\Business;
use App\Models\Message;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CreatesConversationSchema;
use Tests\Support\FakeWhatsAppService;
use Tests\Support\FakeWhatsAppServiceFactory;
use Tests\TestCase;

/**
 * Sonde temporaire (LOG_STATUS_PROBE) : structure des webhooks de coexistence et des réponses à
 * un message inconnu, sans texte, sans numéro complet ni image, et sans rien changer au traitement.
 */
class StatusProbeTest extends TestCase
{
    use CreatesConversationSchema;

    private const CUSTOMER = '22670112233';

    private string $log;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = storage_path('logs/status-probe-test-'.Str::random(8).'.log');
        config([
            'services.whatsapp.status_probe' => true,
            'logging.channels.status_probe.path' => $this->log,
            'services.whatsapp.api_url' => 'https://graph.facebook.com/v21.0/',
        ]);

        $this->createConversationSchema();
        $owner = User::forceCreate(['name' => 'Joyce', 'email' => 'joyce@example.test', 'password' => 'x']);
        $id = (string) Str::uuid();
        DB::table('businesses')->insert([
            'id' => $id, 'user_id' => $owner->id, 'name' => 'Joyce Boutique', 'modules' => '[]',
            'whatsapp_phone_number_id' => 'PNID-JOYCE', 'whatsapp_verified' => true,
        ]);
        $this->business = Business::findOrFail($id);

        FakeWhatsAppService::$sent = [];
        $this->app->instance(WhatsAppServiceFactory::class, new FakeWhatsAppServiceFactory);
        Queue::fake([ReplyToCustomer::class]);
        Http::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->log);

        parent::tearDown();
    }

    public function test_an_owner_echo_is_logged_as_structure_only_and_still_recorded(): void
    {
        $this->webhook('smb_message_echoes', ['message_echoes' => [[
            'from' => '22625000000', 'to' => self::CUSTOMER, 'id' => 'wamid.HBgLMjI2NzAxMTIyMzMVAgARGBI5', 'timestamp' => '1761900000',
            'type' => 'image', 'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'caption' => 'La robe rouge à 12 500 F, appelez le 70 11 22 33'],
        ]]]);

        $line = $this->probeLines()[0];
        $this->assertStringContainsString('STATUS_PROBE webhook hors message client', $line);
        $data = $this->context($line);
        $this->assertSame('smb_message_echoes', $data['field']);
        $this->assertSame($this->business->id, $data['business_id']);
        $this->assertSame(['messaging_product', 'metadata', 'message_echoes'], $data['keys']);
        $this->assertSame([
            'type' => 'image', 'to' => '226…33', 'has_media' => true, 'id' => 'wamid.HBgLMjI2Nz…',
            'keys' => ['from', 'to', 'id', 'timestamp', 'type', 'image'],
        ], $data['items']['message_echoes'][0]);
        $this->assertSame(['id' => 'string(7)', 'mime_type' => 'string(10)', 'caption' => 'string(48)'], $data['structure']['message_echoes']['list(1)']['image']);
        $this->assertNoContent($line);

        // Le traitement n'a pas changé : l'écho est enregistré.
        $this->assertSame(1, Message::where('sender_type', 'human')->count());
    }

    public function test_history_and_delivery_statuses_are_logged_for_a_connected_business_only(): void
    {
        $this->webhook('history', ['history' => [['metadata' => ['phase' => 0], 'threads' => [['id' => self::CUSTOMER, 'messages' => [['text' => ['body' => 'Bonjour, ma robe ?']]]]]]]]);
        $this->webhook('messages', ['statuses' => [['id' => 'wamid.SENT-1-AAAAAAAAAAAAA', 'status' => 'delivered', 'recipient_id' => self::CUSTOMER]]]);
        $this->webhook('smb_message_echoes', ['message_echoes' => []], 'PNID-INCONNU');

        $lines = $this->probeLines();
        $this->assertCount(2, $lines);
        $history = $this->context($lines[0]);
        $this->assertSame('history', $history['field']);
        $this->assertSame(['list(1)' => ['text' => ['body' => 'string(18)']]], $history['structure']['history']['list(1)']['threads']['list(1)']['messages']);
        $statuses = $this->context($lines[1]);
        $this->assertSame(['to' => '226…33', 'has_media' => false, 'id' => 'wamid.SENT-1-AAA…', 'keys' => ['id', 'status', 'recipient_id']], $statuses['items']['statuses'][0]);
        $this->assertSame('delivered', $statuses['structure']['statuses']['list(1)']['status']);
        foreach ($lines as $line) {
            $this->assertNoContent($line);
        }
    }

    public function test_a_reply_to_an_unknown_message_logs_its_full_context_id_and_keys(): void
    {
        $this->webhook('messages', [
            'contacts' => [['profile' => ['name' => 'Awa'], 'wa_id' => self::CUSTOMER]],
            'messages' => [[
                'from' => self::CUSTOMER, 'id' => 'wamid.IN-1', 'timestamp' => '1761900000', 'type' => 'text',
                'text' => ['body' => 'Elle est à combien ? Mon numéro : 70 11 22 33'],
                'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-HBgLMjI2MjUwMDAwMDAVAgARGBJBQ0JF'],
            ]],
        ]);

        $line = collect($this->probeLines())->first(fn (string $line) => str_contains($line, 'context.id inconnu'));
        $this->assertNotNull($line);
        $data = $this->context($line);
        $this->assertSame('wamid.STATUS-HBgLMjI2MjUwMDAwMDAVAgARGBJBQ0JF', $data['context_id']);
        $this->assertSame('226…00', $data['context_from']);
        $this->assertSame('text', $data['type']);
        $this->assertSame(['from', 'id', 'timestamp', 'type', 'text', 'context'], $data['keys']);
        $this->assertSame(['from', 'id'], $data['context_keys']);
        $this->assertFalse($data['has_media']);
        $this->assertNoContent($line);

        // Le message est traité comme avant.
        $this->assertSame(1, Message::where('direction', 'inbound')->count());
    }

    public function test_a_reply_to_a_known_message_or_a_plain_message_is_not_logged(): void
    {
        $this->webhook('messages', ['messages' => [['from' => self::CUSTOMER, 'id' => 'wamid.IN-1', 'type' => 'text', 'text' => ['body' => 'Bonjour']]]]);
        $sent = Message::where('direction', 'inbound')->sole();
        $sent->conversation->messages()->create(['direction' => 'outbound', 'sender_type' => 'ai', 'content' => 'Bonjour !', 'status' => 'sent', 'whatsapp_message_id' => 'wamid.OUT-1']);

        $this->webhook('messages', ['messages' => [[
            'from' => self::CUSTOMER, 'id' => 'wamid.IN-2', 'type' => 'text', 'text' => ['body' => 'Merci'],
            'context' => ['from' => '22625000000', 'id' => 'wamid.OUT-1'],
        ]]]);

        $this->assertSame([], $this->probeLines());
    }

    public function test_nothing_is_logged_without_the_setting(): void
    {
        config(['services.whatsapp.status_probe' => false]);

        $this->webhook('history', ['history' => [['threads' => []]]]);
        $this->webhook('messages', ['messages' => [[
            'from' => self::CUSTOMER, 'id' => 'wamid.IN-1', 'type' => 'text', 'text' => ['body' => 'Combien ?'],
            'context' => ['from' => '22625000000', 'id' => 'wamid.STATUS-1'],
        ]]]);

        $this->assertFileDoesNotExist($this->log);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function webhook(string $field, array $value, string $phoneNumberId = 'PNID-JOYCE'): void
    {
        $this->postJson('/api/webhook/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA-1',
            'changes' => [['field' => $field, 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneNumberId]] + $value]],
        ]]])->assertOk();
    }

    /**
     * @return list<string>
     */
    private function probeLines(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $line): array
    {
        return json_decode(substr($line, strpos($line, '{')), true);
    }

    /** Ni texte du client, ni numéro complet, ni nom, ni identifiant de média. */
    private function assertNoContent(string $line): void
    {
        foreach ([self::CUSTOMER, '22625000000', '70 11 22 33', 'robe rouge', 'ma robe', 'Bonjour', 'combien', 'Awa', '12 500', 'MEDIA-1', 'wamid.IN-1'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $line);
        }
    }
}
