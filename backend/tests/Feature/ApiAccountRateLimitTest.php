<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revue P8-02, F12 : limite globale de l'API par compte connecté (limiteur « api », API_ACCOUNT_RATE_PER_MINUTE).
 * Par compte et jamais par IP : deux joueurs d'un même cybercafé ont chacun leur plafond.
 */
class ApiAccountRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['identity.api_rate_per_minute' => 3]);
    }

    public function test_a_signed_in_player_is_limited_on_every_route(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->getJson('/api/v1/me')->assertOk();
        $this->actingAs($player)->getJson('/api/v1/wallet')->assertOk();
        $this->actingAs($player)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($player)->getJson('/api/v1/wallet')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');

        // Une minute plus tard, le compteur est remis à zéro.
        $this->travel(61)->seconds();
        $this->actingAs($player)->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_limit_is_per_account_not_per_ip(): void
    {
        [$first, $second] = User::factory()->count(2)->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($first)->getJson('/api/v1/me')->assertOk();
        }
        $this->actingAs($first)->getJson('/api/v1/me')->assertStatus(429);

        // Même adresse IP (celle des tests), autre compte : non concerné.
        $this->actingAs($second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_administration_routes_are_limited_too(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
        }
        $this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/dashboard')->assertStatus(429);
    }

    public function test_guests_are_not_counted_by_this_limit(): void
    {
        // Sans compte : seules les limites propres aux routes publiques s'appliquent (ici, aucune sur /me).
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/me')->assertUnauthorized();
        }
    }
}
