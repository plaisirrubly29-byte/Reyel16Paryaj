<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_it_makes_an_existing_account_super_admin_with_audit(): void
    {
        $user = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);

        $this->artisan('identity:grant-role', ['login' => '+509 3412-3456', 'role' => 'super_admin', '--reason' => 'Premier Super Admin'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasPermission('roles.manage'));
        $log = AuditLog::where('action', AuditAction::RolesUpdated)->sole();
        $this->assertSame(['roles' => []], $log->before);
        $this->assertSame(['roles' => ['super_admin']], $log->after);
        $this->assertSame('Premier Super Admin', $log->reason);
        $this->assertNull($log->actor_id);
        $this->assertSame(['source' => 'console'], $log->metadata);

        // Le mot de passe choisi par la personne ne change pas ; le MFA est exigé avant l'administration.
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'motdepasse123'])->assertOk();
        $this->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('error.code', 'mfa_setup_required');
    }

    public function test_it_finds_the_account_by_email(): void
    {
        $user = User::factory()->create(['email' => 'chef@example.com']);

        $this->artisan('identity:grant-role', ['login' => 'chef@example.com', 'role' => 'finance', '--reason' => 'Responsable finance'])
            ->assertSuccessful();

        $this->assertSame(['finance'], $user->fresh()->roles()->pluck('slug')->all());
    }

    public function test_it_asks_for_a_reason_when_missing(): void
    {
        $user = User::factory()->create();

        $this->artisan('identity:grant-role', ['login' => $user->phone, 'role' => 'support'])
            ->expectsQuestion('Motif (5 caractères au moins, enregistré dans le journal d\'audit)', 'abc')
            ->assertExitCode(2);
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_it_refuses_unknown_accounts_roles_and_inactive_accounts(): void
    {
        $this->artisan('identity:grant-role', ['login' => '99999999', 'role' => 'super_admin', '--reason' => 'Motif valide'])
            ->assertFailed();
        $this->artisan('identity:grant-role', ['login' => '99999999', 'role' => 'roi', '--reason' => 'Motif valide'])
            ->assertExitCode(2);

        $suspended = User::factory()->create(['status' => AccountStatus::Suspended]);
        $this->artisan('identity:grant-role', ['login' => $suspended->phone, 'role' => 'super_admin', '--reason' => 'Motif valide'])
            ->assertFailed();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_granting_a_role_twice_changes_nothing(): void
    {
        $user = User::factory()->create();
        $args = ['login' => $user->phone, 'role' => 'operations', '--reason' => 'Équipe opérations'];

        $this->artisan('identity:grant-role', $args)->assertSuccessful();
        $this->artisan('identity:grant-role', $args)->assertSuccessful();

        $this->assertSame(1, $user->roles()->count());
        $this->assertSame(1, AuditLog::count());
    }
}
