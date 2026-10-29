<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Services\MfaService;
use App\Modules\IdentityAccess\Services\Totp;
use App\Support\Http\ApiException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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

    /**
     * Code valide après plusieurs travel() : Totp::verify lit l'heure réelle (time()), fenêtre de ±1 période.
     * La période suivante reste acceptée et n'a pas encore été consommée par la confirmation.
     */
    private function nextRealTimeCode(User $user): string
    {
        return Totp::codeAt($user->fresh()->mfa_secret, intdiv(time(), Totp::PERIOD) + 1);
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

    public function test_an_admin_without_email_can_set_up_mfa(): void
    {
        // L'email est facultatif depuis la connexion par téléphone : le compte de l'URI est alors le numéro (P8-02, F1).
        $this->admin->forceFill(['email' => null])->save();
        $this->login($this->admin);

        $uri = $this->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data.otpauth_uri');

        $this->assertStringContainsString(':'.$this->admin->phone.'?', $uri);
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

    public function test_confirm_changes_the_session_id(): void
    {
        // Revue P8-02, F3 : un identifiant de session fixé avant ne doit pas devenir une session MFA validée.
        // Appel direct : en test, chaque requête HTTP sans cookie repart déjà d'un nouvel identifiant.
        $mfa = app(MfaService::class);
        $session = $this->app['session.store'];
        $mfa->setup($this->admin);
        $before = $session->getId();

        $mfa->confirm($this->admin, $this->currentCode($this->admin), $session);

        $this->assertNotSame($before, $session->getId());
        $this->assertTrue($mfa->isVerified($this->admin, $session));
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

    public function test_a_code_consumed_by_a_concurrent_request_is_refused(): void
    {
        // Revue P8-02, F5 : l'état MFA est relu sous verrou, pas pris dans un modèle chargé avant l'autre requête.
        $this->login($this->admin);
        $recovery = $this->enableMfa();
        $this->relogin();
        $stale = $this->admin->fresh();
        $code = $this->currentCode($this->admin);

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertNoContent();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $recovery[0]])->assertNoContent();

        foreach ([[$code, null], [null, $recovery[0]]] as [$totp, $recoveryCode]) {
            try {
                app(MfaService::class)->verify($stale, $totp, $recoveryCode, $this->app['session.store']);
                $this->fail('Code déjà consommé accepté une seconde fois.');
            } catch (ApiException $e) {
                $this->assertSame('invalid_mfa_code', $e->errorCode);
            }
        }
        $this->assertCount(7, $this->admin->fresh()->mfa_recovery_codes);
    }

    public function test_regenerating_recovery_codes_requires_a_current_totp_code_and_is_audited(): void
    {
        // Revue P8-02, F6 : une session admin volée ne suffit pas à obtenir de nouveaux codes de secours.
        $this->login($this->admin);
        $old = $this->enableMfa();

        $this->postJson('/api/v1/auth/mfa/recovery-codes')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mfa_code');
        $this->assertSame(1, AuditLog::where('action', AuditAction::MfaFailed)->count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::MfaRecoveryCodesRegenerated)->count());

        $code = $this->nextRealTimeCode($this->admin);
        $new = $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $code])->assertOk()->json('data.recovery_codes');

        $this->assertCount(8, $new);
        $this->assertEmpty(array_intersect($old, $new));
        $log = AuditLog::where('action', AuditAction::MfaRecoveryCodesRegenerated)->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['count' => 8], $log->metadata);
        $this->assertStringNotContainsString($new[0], json_encode($log->toArray()));

        // Le code TOTP utilisé ne sert pas deux fois, et un ancien code de secours ne marche plus.
        $this->travel(61)->seconds(); // hors de la limite par minute
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mfa_code');
        $this->relogin();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $old[0]])->assertStatus(422);
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $new[0]])->assertNoContent();
    }

    public function test_regenerating_recovery_codes_is_rate_limited(): void
    {
        $this->login($this->admin);
        $this->enableMfa();
        $this->travel(61)->seconds(); // la confirmation ne compte plus dans la limite par minute

        for ($i = 0; $i < config('identity.mfa.max_attempts_per_minute'); $i++) {
            $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => '000000']);
        }

        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => '000000'])->assertStatus(429);
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

    public function test_verify_is_capped_per_hour(): void
    {
        config(['identity.mfa.max_failures_before_logout' => 100]);
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();

        // Une tentative par minute : seule la limite horaire peut bloquer (la confirmation compte pour 1).
        for ($i = 1; $i < config('identity.mfa.max_attempts_per_hour'); $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->travel(61)->seconds();
        $response = $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->nextRealTimeCode($this->admin)])->assertStatus(429);
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'));
    }

    public function test_verify_is_capped_per_day(): void
    {
        config(['identity.mfa.max_failures_before_logout' => 100, 'identity.mfa.max_attempts_per_hour' => 1000]);
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();

        for ($i = 1; $i < config('identity.mfa.max_attempts_per_day'); $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->travel(61)->seconds();
        $response = $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(429);
        $this->assertGreaterThan(3600, (int) $response->headers->get('Retry-After'));
    }

    public function test_failed_verifications_are_audited_without_the_code(): void
    {
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => 'faux00-code0'])->assertStatus(422);

        $logs = AuditLog::where('action', AuditAction::MfaFailed)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame((string) $this->admin->id, $logs[0]->subject_id);
        $this->assertSame($this->admin->id, $logs[0]->actor_id);
        $this->assertSame(['method' => 'totp', 'consecutive_failures' => 1, 'logged_out' => false], $logs[0]->metadata);
        $this->assertSame(['method' => 'recovery_code', 'consecutive_failures' => 2, 'logged_out' => false], $logs[1]->metadata);
        $this->assertStringNotContainsString('faux00-code0', json_encode($logs->toArray()));
    }

    public function test_too_many_consecutive_failures_close_the_session(): void
    {
        $max = config('identity.mfa.max_failures_before_logout');
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();
        $this->travel(61)->seconds(); // la confirmation ne compte plus dans la limite par minute

        for ($i = 1; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mfa_too_many_failures');
        $this->assertGuest('web');
        $this->assertTrue(AuditLog::where('action', AuditAction::MfaFailed)->latest('id')->first()->metadata['logged_out']);

        // Le compteur est gardé : après reconnexion, un nouvel échec referme aussitôt la session.
        $this->travel(61)->seconds();
        $this->login($this->admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'mfa_too_many_failures');

        // Un code valide, lui, est toujours accepté et remet le compteur à zéro.
        $this->login($this->admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->nextRealTimeCode($this->admin)])->assertNoContent();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_the_session_is_closed_even_if_the_audit_write_fails(): void
    {
        // Revue P8-02, M2 : la déconnexion ne doit pas dépendre de l'écriture en base.
        $max = config('identity.mfa.max_failures_before_logout');
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();
        $this->travel(61)->seconds();

        for ($i = 1; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        AuditLog::creating(fn () => throw new RuntimeException('base indisponible'));
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertServerError();
        AuditLog::flushEventListeners();

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards(); // nouvelle requête : seul compte ce que la session contient encore
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    public function test_successful_verification_resets_the_failure_count(): void
    {
        $max = config('identity.mfa.max_failures_before_logout');
        $this->login($this->admin);
        $this->enableMfa();
        $this->relogin();
        $this->travel(61)->seconds();

        for ($i = 1; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->nextRealTimeCode($this->admin)])->assertNoContent();

        $this->travel(61)->seconds();
        $this->relogin();
        for ($i = 1; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->assertAuthenticated('web');
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
