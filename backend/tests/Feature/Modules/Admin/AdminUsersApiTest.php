<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUsersApiTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create(['name' => 'Ana Joueuse', 'email' => 'ana@example.com']);
        $this->player->profile()->create(['first_name' => 'Ana', 'birth_date' => '1990-04-02', 'phone' => '+50900000000']);
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    private function setStatus(User $admin, User $target, string $status, string $reason = 'Fraude suspectée')
    {
        return $this->actingAsVerifiedAdmin($admin)->patchJson("/api/v1/admin/users/{$target->id}", [
            'status' => $status,
            'reason' => $reason,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_support_sees_minimal_data_only(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson("/api/v1/admin/users/{$this->player->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonMissingPath('data.profile')
            ->assertJsonMissingPath('data.roles');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_compliance_sees_personal_data_and_the_consultation_is_audited(): void
    {
        $compliance = $this->admin(RoleSlug::Compliance);

        $this->actingAsVerifiedAdmin($compliance)
            ->getJson("/api/v1/admin/users/{$this->player->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.birth_date', '1990-04-02')
            ->assertJsonPath('data.profile.phone', '+50900000000');

        $log = AuditLog::where('action', AuditAction::UserPersonalDataViewed)->sole();
        $this->assertSame($compliance->id, $log->actor_id);
        $this->assertSame((string) $this->player->id, $log->subject_id);
    }

    public function test_operations_cannot_access_user_accounts(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_search_filters_and_paginates(): void
    {
        User::factory()->count(3)->create();
        User::factory()->withStatus(AccountStatus::Suspended)->create(['email' => 'suspendu@example.com']);
        $support = $this->admin(RoleSlug::Support);

        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/users?search=ANA@')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $this->player->id);

        $this->actingAsVerifiedAdmin($support)->getJson("/api/v1/admin/users?search={$this->player->id}")
            ->assertJsonPath('data.0.id', (string) $this->player->id);

        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/users?status=suspended')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'suspendu@example.com');

        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/users?role=support')
            ->assertJsonCount(1, 'data');

        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/users?per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_search_wildcards_are_treated_literally(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_support_can_suspend_a_player_with_reason_and_it_is_audited(): void
    {
        $support = $this->admin(RoleSlug::Support);

        $this->setStatus($support, $this->player, 'suspended')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(AccountStatus::Suspended, $this->player->fresh()->status);
        $log = AuditLog::where('action', AuditAction::UserSuspended)->sole();
        $this->assertSame(['status' => 'active'], $log->before);
        $this->assertSame(['status' => 'suspended'], $log->after);
        $this->assertSame('Fraude suspectée', $log->reason);
        $this->assertSame($support->id, $log->actor_id);
    }

    public function test_suspended_player_loses_access_immediately(): void
    {
        $this->setStatus($this->admin(RoleSlug::Support), $this->player, 'suspended')->assertOk();

        // fresh() : en production l'utilisateur est rechargé à chaque requête.
        $this->actingAs($this->player->fresh())->getJson('/api/v1/me')->assertStatus(423);
    }

    public function test_reactivation_is_audited(): void
    {
        $support = $this->admin(RoleSlug::Support);
        $this->setStatus($support, $this->player, 'suspended')->assertOk();

        $this->setStatus($support, $this->player, 'active', 'Vérification terminée')->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(1, AuditLog::where('action', AuditAction::UserReactivated)->count());
    }

    public function test_reason_is_required(): void
    {
        $this->setStatus($this->admin(RoleSlug::Support), $this->player, 'suspended', '')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        $this->assertSame(AccountStatus::Active, $this->player->fresh()->status);
    }

    public function test_self_excluded_account_cannot_be_reactivated_by_an_admin(): void
    {
        $excluded = User::factory()->withStatus(AccountStatus::Excluded)->create();

        $this->setStatus($this->admin(RoleSlug::Compliance), $excluded, 'active')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_status_transition');
    }

    public function test_admin_cannot_change_own_status(): void
    {
        $support = $this->admin(RoleSlug::Support);

        $this->setStatus($support, $support, 'suspended')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_status');
    }

    public function test_only_super_admin_can_suspend_another_admin(): void
    {
        $finance = $this->admin(RoleSlug::Finance);

        $this->setStatus($this->admin(RoleSlug::Support), $finance, 'suspended')->assertForbidden();
        $this->assertSame(AccountStatus::Active, $finance->fresh()->status);

        $this->setStatus($this->admin(RoleSlug::SuperAdmin), $finance, 'suspended')->assertOk();
    }

    public function test_finance_can_view_but_not_suspend(): void
    {
        $this->setStatus($this->admin(RoleSlug::Finance), $this->player, 'suspended')->assertForbidden();
    }

    public function test_player_cannot_reach_admin_users(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($this->player)->getJson("/api/v1/admin/users/{$this->player->id}")->assertForbidden();
    }
}
