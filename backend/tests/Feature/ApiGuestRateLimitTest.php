<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-17, PS-5 (V2-F04) : limite globale de l'API pour un visiteur non connecté, par IP, en plus des limites de chaque
 * route (IDENTITY_API_GUEST_RATE_PER_MINUTE, 300 par défaut, 0 = sans limite). Les webhooks de paiement n'y sont pas
 * comptés.
 */
class ApiGuestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['identity.api_guest_rate_per_minute' => 3]);
    }

    public function test_a_guest_is_limited_per_ip_on_every_route(): void
    {
        // Route publique (catalogue, sa propre limite est de 120/min) : la limite des visiteurs s'y ajoute.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/sports')->assertOk();
        }

        $this->getJson('/api/v1/sports')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');

        // Autre adresse : son propre compteur.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->getJson('/api/v1/sports')->assertOk();

        // Une minute plus tard, le compteur est remis à zéro.
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('/api/v1/sports')->assertOk();
    }

    public function test_signed_in_accounts_are_not_counted_as_guests(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/sports');
        }
        $this->getJson('/api/v1/sports')->assertStatus(429);

        // Même IP, mais connecté : la limite par compte s'applique, pas celle des visiteurs.
        $this->actingAs(User::factory()->create())->getJson('/api/v1/sports')->assertOk();
    }

    public function test_zero_disables_the_guest_limit(): void
    {
        config(['identity.api_guest_rate_per_minute' => 0]);

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/sports')->assertOk();
        }
    }

    public function test_payment_webhooks_are_not_counted_in_the_guest_limit(): void
    {
        // Le webhook ne reçoit pas le limiteur global (il garde payments-webhook, 300/min par IP).
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/payments/webhooks/{provider}');

        $this->assertNotNull($route);
        $this->assertNotContains('throttle:api', $route->gatherMiddleware());
        $this->assertContains('throttle:payments-webhook', $route->gatherMiddleware());
    }
}
