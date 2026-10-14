<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_returns_api_entrypoint(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonStructure(['name', 'api']);
    }

    public function test_api_health_endpoint_is_versioned(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'version' => 'v1']);
    }
}
