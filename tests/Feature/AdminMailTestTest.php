<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class AdminMailTestTest extends TestCase
{
    use CreatesConversationSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'is_admin' => true]);
    }

    public function test_sends_a_test_email_to_the_admin_and_shows_the_config_without_the_password(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.array.host' => 'smtp.example.test',
            'mail.mailers.array.password' => 'mot-de-passe-secret',
            'mail.from.address' => 'contact@causeo.test',
        ]);
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message;
        });

        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/admin/mail-test')
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('delivered_by_network', false)
            ->assertJsonPath('to', 'admin@example.test')
            ->assertJsonPath('mailer', 'array')
            ->assertJsonPath('host', 'smtp.example.test')
            ->assertJsonPath('password_set', true)
            ->assertJsonPath('from_address', 'contact@causeo.test');

        $this->assertStringNotContainsString('mot-de-passe-secret', $response->getContent());
        $this->assertCount(1, $sent);
        $this->assertSame('admin@example.test', $sent[0]->getTo()[0]->getAddress());
        $this->assertSame('Causeo : e-mail de test', $sent[0]->getSubject());
    }

    public function test_returns_the_exact_error_when_sending_fails(): void
    {
        config(['mail.default' => 'inexistant']);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/mail-test')
            ->assertStatus(502)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('mailer', 'inexistant')
            ->assertJsonPath('error.class', 'InvalidArgumentException')
            ->assertJsonPath('error.message', 'Mailer [inexistant] is not defined.');
    }

    public function test_is_reserved_to_admins(): void
    {
        Sanctum::actingAs(User::forceCreate(['name' => 'Gérant', 'email' => 'owner@example.test', 'password' => 'x']));

        $this->postJson('/api/v1/admin/mail-test')->assertForbidden();
    }
}
