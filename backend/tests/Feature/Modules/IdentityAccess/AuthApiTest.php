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
            'phone' => '34123456',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'birth_date' => now()->subYears(25)->toDateString(),
            'country' => 'ht',
        ], $overrides);
    }

    public function test_register_creates_an_active_player_and_opens_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData(['email' => 'Joueur@Example.com']))
            ->assertCreated()
            ->assertJsonPath('data.phone', '34123456')
            ->assertJsonPath('data.email', 'joueur@example.com')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.profile.country', 'HT')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticated('web');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.phone', '34123456');
    }

    public function test_register_without_email_succeeds_and_stores_a_null_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData())
            ->assertCreated()
            ->assertJsonPath('data.phone', '34123456')
            ->assertJsonPath('data.email', null);

        $this->assertSame(null, User::first()->email);
    }

    public function test_register_accepts_a_phone_with_spaces_dashes_and_a_509_prefix(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '+509 34-12 34-56']))
            ->assertCreated()
            ->assertJsonPath('data.phone', '34123456');
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

    public function test_register_rejects_a_phone_that_does_not_normalize_to_eight_digits(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '123']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);

        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '341234567']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);

        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => 'abcdefgh']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);
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

    public function test_register_rejects_duplicate_phone_even_formatted_differently(): void
    {
        User::factory()->create(['phone' => '34123456']);

        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '+509 3412-3456']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);
    }

    public function test_register_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'joueur@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationData([
            'phone' => '34123457',
            'email' => 'joueur@example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_two_players_can_both_register_without_an_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '34123456']))->assertCreated();
        $this->postJson('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/register', $this->registrationData(['phone' => '34123457']))->assertCreated();

        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, User::whereNull('email')->count());
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
        $user = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        $this->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'motdepasse123'])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_normalizes_the_phone_the_same_way_as_registration(): void
    {
        $user = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        $this->postJson('/api/v1/auth/login', ['phone' => '+509 34-12 34-56', 'password' => 'motdepasse123'])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);
    }

    public function test_unknown_account_and_wrong_password_return_the_same_error(): void
    {
        User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'mauvais-mdp1']);
        $unknownAccount = $this->postJson('/api/v1/auth/login', ['phone' => '34999999', 'password' => 'mauvais-mdp1']);

        $wrongPassword->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame($wrongPassword->json(), $unknownAccount->json());
        $this->assertSame($wrongPassword->status(), $unknownAccount->status());
        $this->assertGuest('web');
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        User::factory()->withStatus(AccountStatus::Suspended)->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        $this->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'motdepasse123'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_suspended');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['phone' => '34123456']);

        for ($i = 0; $i < config('identity.login.max_attempts_per_phone'); $i++) {
            $this->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'mauvais-mdp1'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'mauvais-mdp1'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_login_limit_counts_every_spelling_of_the_same_phone(): void
    {
        User::factory()->create(['phone' => '34123456']);

        foreach (['34123456', '3412-3456', '+509 3412 3456', '509-34-12-34-56', '34 12 34 56'] as $spelling) {
            $this->postJson('/api/v1/auth/login', ['phone' => $spelling, 'password' => 'mauvais-mdp1'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['phone' => '(509) 3412.3456', 'password' => 'mauvais-mdp1'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_login_is_limited_per_account_whatever_the_ip(): void
    {
        User::factory()->create(['phone' => '34123456']);
        User::factory()->create(['phone' => '37654321']);

        for ($i = 0; $i < config('identity.login.max_attempts_per_account'); $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'mauvais-mdp1'])
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->postJson('/api/v1/auth/login', ['phone' => '+509 3412-3456', 'password' => 'mauvais-mdp1'])
            ->assertStatus(429);

        // Les autres comptes ne sont pas touchés.
        $this->postJson('/api/v1/auth/login', ['phone' => '37654321', 'password' => 'mauvais-mdp1'])->assertStatus(422);
    }

    public function test_login_with_a_non_string_phone_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['phone' => ['34123456'], 'password' => 'mauvais-mdp1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse123']);
        $this->postJson('/api/v1/auth/login', ['phone' => $user->phone, 'password' => 'motdepasse123'])->assertOk();

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
