<?php

namespace Tests\Feature;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Les adresses de nos services viennent de la configuration (FRONTEND_URL,
 * CORS_ALLOWED_ORIGINS), jamais d'une URL écrite en dur.
 */
class AppUrlsTest extends TestCase
{
    public function test_google_login_redirects_to_the_configured_dashboard(): void
    {
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_04_033251_create_personal_access_tokens_table.php',
            '2026_09_04_042020_create_businesses_table.php',
            '2026_09_11_162450_add_google_id_to_users_table.php',
            '2026_09_11_200158_add_is_admin_to_users_table.php',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}"))->up();
        }

        config(['app.frontend_url' => 'https://app.causeo.example/']);
        Socialite::shouldReceive('driver->user')->andReturn(
            (new SocialiteUser())->map(['id' => 'g-1', 'name' => 'Awa', 'email' => 'awa@example.test']),
        );

        $location = $this->get('/auth/google/callback')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://app.causeo.example/auth/callback?token=', $location);
    }

    public function test_cors_allows_every_configured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://old-dashboard.example', 'https://app.causeo.example']]);

        foreach (['https://old-dashboard.example', 'https://app.causeo.example'] as $origin) {
            $this->options('/api/v1/plans', [], ['Origin' => $origin, 'Access-Control-Request-Method' => 'GET'])
                ->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        $this->options('/api/v1/plans', [], ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
