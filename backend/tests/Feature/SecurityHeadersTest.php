<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Revue P8-02, F13 : en-têtes de sécurité sur toutes les réponses de l'API, y compris les erreurs. */
class SecurityHeadersTest extends TestCase
{
    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSecurityHeaders($response);
        $response->assertHeaderMissing('Strict-Transport-Security'); // pas en HTTP
    }

    public function test_error_responses_carry_security_headers_too(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/v1/route-inconnue')->assertNotFound());
        $this->assertSecurityHeaders($this->getJson('/api/v1/me')->assertUnauthorized());
    }

    public function test_responses_returned_before_the_route_carry_them_too(): void
    {
        // Préflight CORS : renvoyé par HandleCors, avant la route et les middlewares de groupe.
        $this->assertSecurityHeaders($this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent());

        // Maintenance (php artisan down) : 503 renvoyé avant toute route.
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->assertSecurityHeaders($this->getJson('/api/v1/health')->assertStatus(503));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->getJson('https://localhost/api/v1/health')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
