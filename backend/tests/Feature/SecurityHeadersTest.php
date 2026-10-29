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

    public function test_hsts_is_sent_over_https(): void
    {
        $this->getJson('https://localhost/api/v1/health')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
