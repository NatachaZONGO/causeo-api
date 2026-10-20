<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesConversationSchema;
use Tests\TestCase;

class ApiCachingHeadersTest extends TestCase
{
    use CreatesConversationSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createConversationSchema();
    }

    public function test_public_json_responses_are_not_cacheable(): void
    {
        $this->assertNotCacheable($this->getJson('/api/v1/plans')->assertOk());
    }

    public function test_error_responses_are_not_cacheable(): void
    {
        // Sans jeton, route inconnue, exception non gérée.
        $this->assertNotCacheable($this->getJson('/api/v1/auth/me')->assertUnauthorized());
        $this->assertNotCacheable($this->getJson('/api/v1/route-inconnue')->assertNotFound());

        Route::get('api/test-exception', fn () => throw new \RuntimeException('boom'));
        $this->assertNotCacheable($this->getJson('/api/test-exception')->assertStatus(500));
    }

    public function test_cache_headers_set_by_a_route_are_overridden(): void
    {
        Route::get('api/test-cacheable', fn () => response()->json(['ok' => true])
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Expires', 'Wed, 21 Oct 2026 07:28:00 GMT')
            ->setEtag('abc'));

        $response = $this->getJson('/api/test-cacheable')->assertOk();

        $this->assertNotCacheable($response);
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Expires');
        $response->assertHeaderMissing('ETag');
    }

    public function test_routes_outside_the_api_are_untouched(): void
    {
        $this->get('/up')->assertOk()->assertHeaderMissing('Pragma');
    }

    private function assertNotCacheable(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Pragma', 'no-cache');
    }
}
