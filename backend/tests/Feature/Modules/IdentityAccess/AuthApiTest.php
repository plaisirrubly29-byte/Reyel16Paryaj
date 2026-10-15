<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Requêtes issues du frontend first-party : Sanctum active la session (mode SPA).
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function registrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Joueur Test',
            'email' => 'Joueur@Example.com',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'birth_date' => now()->subYears(25)->toDateString(),
            'country' => 'ht',
        ], $overrides);
    }

    public function test_register_creates_an_active_player_and_opens_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData())
            ->assertCreated()
            ->assertJsonPath('data.email', 'joueur@example.com')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.profile.country', 'HT')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticated('web');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'joueur@example.com');
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData([
            'email' => 'pas-un-email',
            'password' => 'court',
            'password_confirmation' => 'different',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['email', 'password']]]);
    }

    public function test_register_rejects_minors(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData([
            'birth_date' => now()->subYears(18)->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['birth_date']]]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'joueur@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationData(['email' => 'joueur@example.com']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_status_and_roles_cannot_be_injected_at_registration(): void
    {
        $this->seed(RoleSeeder::class);

        $this->postJson('/api/v1/auth/register', $this->registrationData([
            'status' => 'closed',
            'roles' => ['super_admin'],
            'is_admin' => true,
        ]))->assertCreated()->assertJsonPath('data.roles', [])->assertJsonPath('data.status', 'active');
    }

    public function test_login_returns_the_user_and_opens_a_session(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'motdepasse123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'A@example.com', 'password' => 'motdepasse123'])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_unknown_account_and_wrong_password_return_the_same_error(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'motdepasse123']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'mauvais-mdp1']);
        $unknownAccount = $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.com', 'password' => 'mauvais-mdp1']);

        $wrongPassword->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame($wrongPassword->json(), $unknownAccount->json());
        $this->assertSame($wrongPassword->status(), $unknownAccount->status());
        $this->assertGuest('web');
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        User::factory()->withStatus(AccountStatus::Suspended)->create(['email' => 'a@example.com', 'password' => 'motdepasse123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'motdepasse123'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_suspended');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        for ($i = 0; $i < config('identity.login.max_attempts_per_email'); $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'mauvais-mdp1'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'mauvais-mdp1'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse123']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'motdepasse123'])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_guest_without_json_accept_header_gets_401_not_a_redirect(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_exposes_roles_for_admins(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::Finance)->create();

        $this->actingAs($admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['finance'])
            ->assertJsonPath('data.permissions', collect(config('permissions.roles.finance'))->sort()->values()->all())
            ->assertJsonPath('data.is_admin', true);
    }
}
