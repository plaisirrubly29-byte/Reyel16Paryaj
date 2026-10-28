<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_frontend_origin_may_call_api_with_credentials(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_preflight_allows_idempotency_key_header(): void
    {
        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,idempotency-key',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
