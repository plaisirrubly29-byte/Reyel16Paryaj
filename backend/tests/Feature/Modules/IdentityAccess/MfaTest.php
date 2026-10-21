<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Services\Totp;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->admin = User::factory()->withRole(RoleSlug::Compliance)->create(['password' => 'motdepasse123']);
    }

    private function login(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['phone' => $user->phone, 'password' => 'motdepasse123'])->assertOk();
    }

    private function currentCode(User $user): string
    {
        return Totp::codeAt($user->fresh()->mfa_secret, intdiv(now()->getTimestamp(), Totp::PERIOD));
    }

    /** Active le MFA via l'API et renvoie les codes de secours. */
    private function enableMfa(): array
    {
        $this->postJson('/api/v1/auth/mfa/setup')->assertOk();

        return $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->currentCode($this->admin)])
            ->assertOk()
            ->json('data.recovery_codes');
    }

    private function relogin(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->travel(Totp::PERIOD)->seconds(); // nouveau code TOTP
        $this->login($this->admin);
    }

    public function test_admin_without_mfa_cannot_reach_admin_routes(): void
    {
        $this->login($this->admin);

        $this->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_setup_required');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.mfa', ['enabled' => false, 'verified' => false, 'required' => true]);
    }

    public function test_setup_and_confirm_enable_mfa_and_verify_the_session(): void
    {
        $this->login($this->admin);

        $setup = $this->postJson('/api/v1/auth/mfa/setup')->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_uri'));
        $this->assertSame($setup->json('data.secret'), $this->admin->fresh()->mfa_secret);

        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->currentCode($this->admin)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $codes);
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('data.mfa', ['enabled' => true, 'verified' => true, 'required' => true]);
    }

    public function test_confirm_rejects_a_wrong_code(): void
    {
        $this->login($this->admin);
        $this->postJson('/api/v1/auth/mfa/setup')->assertOk();

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mfa_code');

        $this->assertNull($this->admin->fresh()->mfa_confirmed_at);
    }

    public function test_secret_and_recovery_codes_are_not_stored_in_clear(): void
    {
        $this->login($this->admin);
        $codes = $this->enableMfa();

        $row = DB::table('users')->where('id', $this->admin->id)->first();
        $this->assertStringNotContainsString($this->admin->fresh()->mfa_secret, $row->mfa_secret);
        $this->assertStringNotContainsString($codes[0], $row->mfa_recovery_codes);
        $this->assertArrayNotHasKey('mfa_secret', $this->admin->fresh()->toArray());
    }

    public function test_new_login_requires_a_new_mfa_verification(): void
    {
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();

        $this->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_required');

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->currentCode($this->admin)])->assertNoContent();

        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $this->login($this->admin);
        $this->enableMfa();
        $usedCode = $this->currentCode($this->admin);

        $this->postJson('/api/v1/auth/logout');
        $this->login($this->admin);

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $usedCode])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mfa_code');
    }

    public function test_recovery_code_works_only_once(): void
    {
        $this->login($this->admin);
        $codes = $this->enableMfa();

        $this->relogin();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => strtoupper($codes[0])])->assertNoContent();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();

        $this->relogin();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertStatus(422);
        $this->assertCount(7, $this->admin->fresh()->mfa_recovery_codes);
    }

    public function test_verify_is_rate_limited(): void
    {
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();

        // La confirmation a déjà consommé 1 des 5 essais de la minute.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(429);
    }

    public function test_setup_is_refused_once_mfa_is_enabled(): void
    {
        $this->login($this->admin);
        $this->enableMfa();

        $this->postJson('/api/v1/auth/mfa/setup')->assertStatus(409)->assertJsonPath('error.code', 'mfa_already_enabled');
    }

    public function test_players_are_not_required_to_use_mfa(): void
    {
        $player = User::factory()->create(['password' => 'motdepasse123']);
        $this->login($player);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa.required', false);
    }

    public function test_super_admin_can_reset_another_admins_mfa_with_audit(): void
    {
        $super = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $this->admin->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();

        $this->actingAsVerifiedAdmin($super)
            ->deleteJson("/api/v1/admin/users/{$this->admin->id}/mfa", ['reason' => 'Téléphone perdu'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNoContent();

        $this->assertNull($this->admin->fresh()->mfa_confirmed_at);
        $log = AuditLog::where('action', AuditAction::MfaReset)->sole();
        $this->assertSame('Téléphone perdu', $log->reason);
        $this->assertSame($super->id, $log->actor_id);
    }

    public function test_admin_cannot_reset_own_mfa(): void
    {
        $super = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->actingAsVerifiedAdmin($super)
            ->deleteJson("/api/v1/admin/users/{$super->id}/mfa", ['reason' => 'Test motif'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_reset_own_mfa');

        $this->assertNotNull($super->fresh()->mfa_confirmed_at);
    }

    public function test_non_super_admin_cannot_reset_any_mfa(): void
    {
        $super = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->actingAsVerifiedAdmin($this->admin)
            ->deleteJson("/api/v1/admin/users/{$super->id}/mfa", ['reason' => 'Test motif'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }
}
