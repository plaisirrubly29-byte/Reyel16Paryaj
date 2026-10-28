<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Permission;
use App\Modules\IdentityAccess\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        Route::middleware(['api', 'auth:sanctum', 'active'])->prefix('api/v1/_test')->group(function () {
            Route::get('/ledger', fn () => ['ok' => true])->middleware('permission:ledger.view');
            Route::get('/audit-or-ledger', fn () => ['ok' => true])->middleware('permission:audit.view|ledger.view');
        });
    }

    private function putRoles(User $target, array $roles, string $reason = 'Changement de poste')
    {
        return $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => $roles, 'reason' => $reason], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    public function test_seeded_matrix_matches_the_config_and_is_idempotent(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertSame(count(config('permissions.permissions')), Permission::count());

        foreach (config('permissions.roles') as $role => $permissions) {
            $this->assertEqualsCanonicalizing(
                $permissions,
                Role::where('slug', $role)->firstOrFail()->permissions()->pluck('slug')->all(),
                "Permissions du rôle {$role}",
            );
        }
    }

    public function test_only_super_admin_can_manage_roles(): void
    {
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'roles.manage'))->pluck('slug')->all();

        $this->assertSame(['super_admin'], $holders);
    }

    public function test_permission_middleware_allows_and_denies(): void
    {
        $finance = User::factory()->withRole(RoleSlug::Finance)->create();
        $operations = User::factory()->withRole(RoleSlug::Operations)->create();
        $player = User::factory()->create();

        $this->actingAs($finance)->getJson('/api/v1/_test/ledger')->assertOk();
        $this->actingAsVerifiedAdmin($operations)->getJson('/api/v1/_test/ledger')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->actingAs($player)->getJson('/api/v1/_test/ledger')->assertForbidden();
    }

    public function test_permission_middleware_accepts_any_of_several_permissions(): void
    {
        $compliance = User::factory()->withRole(RoleSlug::Compliance)->create();
        $support = User::factory()->withRole(RoleSlug::Support)->create();

        $this->actingAsVerifiedAdmin($compliance)->getJson('/api/v1/_test/audit-or-ledger')->assertOk();
        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/_test/audit-or-ledger')->assertForbidden();
    }

    public function test_guest_gets_401_not_403(): void
    {
        $this->getJson('/api/v1/_test/ledger')->assertUnauthorized();
    }

    public function test_permissions_are_usable_as_gate_abilities(): void
    {
        $support = User::factory()->withRole(RoleSlug::Support)->create();

        $this->assertTrue(Gate::forUser($support)->allows('users.suspend'));
        $this->assertFalse(Gate::forUser($support)->allows('finance.correct'));
        $this->assertFalse(Gate::forUser($support)->allows('users.view_personal_data'));
    }

    public function test_suspended_account_loses_access_immediately_even_with_an_open_session(): void
    {
        $finance = User::factory()->withRole(RoleSlug::Finance)->create();
        $this->actingAs($finance)->getJson('/api/v1/_test/ledger')->assertOk();

        $finance->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->getJson('/api/v1/_test/ledger')->assertStatus(423)->assertJsonPath('error.code', 'account_suspended');
        $this->getJson('/api/v1/me')->assertStatus(423);
    }

    public function test_super_admin_can_assign_roles_to_another_admin(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $target = User::factory()->withRole(RoleSlug::Support)->create();

        $this->actingAsVerifiedAdmin($admin)->putRoles($target, ['support', 'finance'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['finance', 'support']);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => Role::where('slug', 'finance')->value('id'),
            'assigned_by' => $admin->id,
        ]);
    }

    public function test_admin_cannot_modify_own_roles(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->actingAsVerifiedAdmin($admin)->putRoles($admin, [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_modify_own_roles');

        $this->assertTrue($admin->fresh()->hasRole(RoleSlug::SuperAdmin));
    }

    public function test_admin_without_roles_manage_cannot_elevate_anyone_including_self(): void
    {
        $support = User::factory()->withRole(RoleSlug::Support)->create();
        $other = User::factory()->create();

        $this->actingAsVerifiedAdmin($support)->putRoles($support, ['super_admin'])->assertForbidden();
        $this->actingAsVerifiedAdmin($support)->putRoles($other, ['super_admin'])->assertForbidden();

        $this->assertFalse($support->fresh()->hasRole(RoleSlug::SuperAdmin));
        $this->assertSame(0, $other->roles()->count());
    }

    public function test_player_cannot_reach_admin_routes_by_guessing_ids(): void
    {
        $player = User::factory()->create();
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        // Accès direct par identifiant (IDOR) : refusé avant toute lecture de la cible.
        $this->actingAs($player)->putRoles($admin, [])->assertForbidden();
        $this->actingAs($player)->putRoles($player, ['super_admin'])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole(RoleSlug::SuperAdmin));
    }

    public function test_unauthorized_user_cannot_probe_which_ids_exist(): void
    {
        $support = User::factory()->withRole(RoleSlug::Support)->create();
        $existing = User::factory()->create();

        $forExisting = $this->actingAsVerifiedAdmin($support)->putRoles($existing, ['support']);
        $forMissing = $this->actingAsVerifiedAdmin($support)->putJson('/api/v1/admin/users/999999/roles', ['roles' => ['support'], 'reason' => 'Test motif'], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $forExisting->assertForbidden();
        $forMissing->assertForbidden();
    }

    public function test_unknown_target_returns_404_and_unknown_role_is_rejected(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $target = User::factory()->create();

        $this->actingAsVerifiedAdmin($admin)->putJson('/api/v1/admin/users/999999/roles', ['roles' => [], 'reason' => 'Test motif'], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertNotFound();

        $this->actingAsVerifiedAdmin($admin)->putRoles($target, ['god_mode'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['roles.0']]]);
    }

    public function test_reason_is_mandatory(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $target = User::factory()->create();

        $this->actingAsVerifiedAdmin($admin)->putRoles($target, ['support'], '')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);
    }

    public function test_role_assignment_requires_an_idempotency_key(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $target = User::factory()->create();

        $this->actingAsVerifiedAdmin($admin)->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['support'], 'reason' => 'Nouveau poste'])
            ->assertStatus(428);
    }
}
