<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class WebServerConfigTest extends TestCase
{
    use CreatesConversationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        Http::fake();
        Log::spy();
    }

    /**
     * Sous php artisan serve, sans Content-Length, Meta attendrait la fin du
     * traitement lancé avec afterResponse() avant de considérer la réponse reçue.
     */
    public function test_every_webhook_acknowledgement_announces_its_length(): void
    {
        $payloads = [
            'message' => $this->payload('messages', [
                'metadata' => ['phone_number_id' => 'PNID-UNKNOWN'],
                'messages' => [['from' => '22670000001', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
            ]),
            'non-text message' => $this->payload('messages', [
                'metadata' => ['phone_number_id' => 'PNID-UNKNOWN'],
                'messages' => [['from' => '22670000001', 'id' => 'wamid.2', 'type' => 'image']],
            ]),
            'echoes' => $this->payload('smb_message_echoes', ['metadata' => ['phone_number_id' => 'PNID-UNKNOWN'], 'message_echoes' => []]),
            'account_update' => $this->payload('account_update', ['event' => 'PARTNER_ADDED']),
        ];

        foreach ($payloads as $case => $payload) {
            $this->postJson('/api/webhook/whatsapp', $payload)
                ->assertOk()
                ->assertHeader('Content-Length', '0')
                ->assertContent('');
        }
    }

    public function test_docker_image_runs_several_php_server_workers(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^ENV PHP_CLI_SERVER_WORKERS=(\d+)$/m', $dockerfile);
        preg_match('/^ENV PHP_CLI_SERVER_WORKERS=(\d+)$/m', $dockerfile, $workers);
        $this->assertGreaterThanOrEqual(2, (int) $workers[1]);

        // Laravel ignore PHP_CLI_SERVER_WORKERS sans --no-reload.
        $this->assertMatchesRegularExpression('/^CMD .*php artisan serve .*--no-reload/m', $dockerfile);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function payload(string $field, array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-X', 'changes' => [['field' => $field, 'value' => $value]]]]];
    }
}
