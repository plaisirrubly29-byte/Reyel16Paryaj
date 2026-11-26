<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use App\Modules\IdentityAccess\Services\Totp;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Audit du 18/11/2026 : actions de sécurité tracées dans le journal d'audit — réinitialisation du mot de passe,
 * activation et validation de la double authentification, déconnexion. Jamais de mot de passe, de code, de secret
 * ni de jeton dans le journal.
 */
class SecurityAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function entries(AuditAction $action): Collection
    {
        return AuditLog::where('action', $action->value)->get();
    }

    /** Aucune valeur secrète dans les colonnes du journal. */
    private function assertNoSecretIn(AuditLog $entry, array $secrets): void
    {
        $stored = json_encode([$entry->before, $entry->after, $entry->metadata, $entry->reason]);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_a_password_reset_is_audited_without_password_or_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'ancienmdp123']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com'])->assertStatus(202);
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        // Jeton faux : rien n'est tracé.
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'faux', 'email' => 'ana@example.com', 'password' => 'nouveaumdp456', 'password_confirmation' => 'nouveaumdp456'])
            ->assertStatus(422);
        $this->assertCount(0, $this->entries(AuditAction::PasswordReset));

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'ana@example.com', 'password' => 'nouveaumdp456', 'password_confirmation' => 'nouveaumdp456'])
            ->assertOk();

        $entry = $this->entries(AuditAction::PasswordReset)->sole();
        $this->assertSame((string) $user->id, (string) $entry->subject_id);
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame(['sessions_closed' => true], $entry->after);
        $this->assertNotNull($entry->ip_address);
        $this->assertNoSecretIn($entry, ['nouveaumdp456', 'ancienmdp123', $token]);
    }

    public function test_mfa_activation_and_verification_are_audited_with_the_method_only(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::Compliance)->create(['password' => 'motdepasse123']);
        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'motdepasse123'])->assertOk();

        $secret = $this->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data.secret');
        $code = Totp::codeAt($secret, intdiv(now()->getTimestamp(), Totp::PERIOD));
        $recovery = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk()->json('data.recovery_codes');

        $enabled = $this->entries(AuditAction::MfaEnabled)->sole();
        $this->assertSame($admin->id, $enabled->actor_id);
        $this->assertSame(['mfa_enabled' => true], $enabled->after);
        $this->assertNoSecretIn($enabled, [$secret, $code, ...$recovery]);

        // Nouvelle session validée avec un code de secours : la méthode est tracée, jamais le code.
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'motdepasse123'])->assertOk();
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $recovery[0]])->assertNoContent();

        $verified = $this->entries(AuditAction::MfaVerified)->sole();
        $this->assertSame(['method' => 'recovery_code'], $verified->metadata);
        $this->assertNoSecretIn($verified, [$recovery[0], $secret]);

        // Code faux : échec tracé comme avant (mfa.failed), pas de validation.
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'motdepasse123'])->assertOk();
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        $this->assertCount(1, $this->entries(AuditAction::MfaVerified));
    }

    public function test_a_logout_is_audited_for_the_account_and_nothing_for_a_guest(): void
    {
        $player = User::factory()->create(['password' => 'motdepasse123']);
        $this->postJson('/api/v1/auth/login', ['phone' => $player->phone, 'password' => 'motdepasse123'])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $entry = $this->entries(AuditAction::Logout)->sole();
        $this->assertSame($player->id, $entry->actor_id);
        $this->assertSame((string) $player->id, (string) $entry->subject_id);

        // Déjà déconnecté : rien de plus.
        $this->postJson('/api/v1/auth/logout');
        $this->assertCount(1, $this->entries(AuditAction::Logout));
    }

    public function test_the_new_actions_need_no_written_reason(): void
    {
        foreach ([AuditAction::Logout, AuditAction::PasswordReset, AuditAction::MfaEnabled, AuditAction::MfaVerified] as $action) {
            $this->assertFalse($action->requiresReason(), $action->value);
        }
    }
}
