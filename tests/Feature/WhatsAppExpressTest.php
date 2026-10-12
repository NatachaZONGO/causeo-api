<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Mode Express (bridge Baileys) : désactivé par défaut, ses webhooks répondent 404.
 */
class WhatsAppExpressTest extends TestCase
{
    private const ROUTES = ['/api/webhook/whatsapp-express', '/api/webhook/whatsapp-express/status'];

    public function test_express_webhooks_are_disabled_by_default(): void
    {
        $this->assertFalse(config('services.whatsapp_bridge.enabled'));

        $this->assertExpressRoutesReturn(404, 'secret_bridge_token_2024');
    }

    public function test_express_webhooks_stay_disabled_without_a_real_token(): void
    {
        foreach ([
            'désactivé avec un vrai jeton' => [false, 'un-vrai-secret'],
            'activé sans jeton' => [true, null],
            'activé avec un jeton vide' => [true, '   '],
            'activé avec l\'ancien jeton par défaut' => [true, 'secret_bridge_token_2024'],
        ] as $case => [$enabled, $token]) {
            config(['services.whatsapp_bridge.enabled' => $enabled, 'services.whatsapp_bridge.token' => $token]);

            $this->assertExpressRoutesReturn(404, (string) $token, $case);
        }
    }

    public function test_express_webhooks_answer_when_enabled_with_a_real_token(): void
    {
        config(['services.whatsapp_bridge.enabled' => true, 'services.whatsapp_bridge.token' => 'un-vrai-secret']);

        // Les routes existent : un mauvais jeton est refusé (401), pas introuvable.
        $this->assertExpressRoutesReturn(401, 'mauvais-jeton');
        $this->assertExpressRoutesReturn(401, '');

        // Le bon jeton passe l'authentification et arrive à la validation.
        $this->assertExpressRoutesReturn(422, 'un-vrai-secret');
    }

    private function assertExpressRoutesReturn(int $status, string $token, string $case = ''): void
    {
        foreach (self::ROUTES as $route) {
            $this->postJson($route, [], ['X-Bridge-Token' => $token])
                ->assertStatus($status, "{$route} {$case}");
        }
    }
}
