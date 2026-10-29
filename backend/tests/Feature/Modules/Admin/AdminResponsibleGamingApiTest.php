<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Compliance\Enums\LimitPeriod;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Models\ResponsibleGamingRestriction;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** P7-02 (partie 2) : jeu responsable d'un joueur vu par la Conformité — consultation, réouverture après échéance. */
class AdminResponsibleGamingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    private function excludePlayer(string $duration = '6m'): void
    {
        app(ResponsibleGamingService::class)->selfExclude($this->player, $duration, 'motdepasse123');
    }

    private function reopen(?User $as = null, string $reason = 'Demande écrite du joueur reçue le jour même'): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as ?? $this->admin(RoleSlug::Compliance))
            ->postJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming/reopen", ['reason' => $reason], [
                'Idempotency-Key' => (string) Str::uuid(),
            ]);
    }

    public function test_compliance_and_super_admin_can_view_other_roles_cannot(): void
    {
        foreach ([RoleSlug::Compliance, RoleSlug::SuperAdmin] as $role) {
            $this->actingAsVerifiedAdmin($this->admin($role))
                ->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")
                ->assertOk();
        }

        foreach ([RoleSlug::Operations, RoleSlug::Finance, RoleSlug::Support] as $role) {
            $this->actingAsVerifiedAdmin($this->admin($role))
                ->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")->assertUnauthorized();
    }

    public function test_only_compliance_can_reopen_an_exclusion(): void
    {
        $this->excludePlayer();
        $this->travel(6)->months();
        $this->travel(1)->days();

        foreach ([RoleSlug::SuperAdmin, RoleSlug::Operations, RoleSlug::Finance, RoleSlug::Support] as $role) {
            $this->reopen($this->admin($role))->assertForbidden();
        }
        $this->assertSame(AccountStatus::Excluded, $this->player->fresh()->status);

        $this->reopen()->assertOk();
    }

    public function test_the_view_shows_limits_restrictions_and_journal_and_is_audited(): void
    {
        $service = app(ResponsibleGamingService::class);
        $service->setLimit($this->player, LimitPeriod::Week, 500_00);
        $service->pause($this->player, '7d');
        $compliance = $this->admin(RoleSlug::Compliance);

        $this->actingAsVerifiedAdmin($compliance)
            ->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")
            ->assertOk()
            ->assertJsonPath('data.user', ['id' => (string) $this->player->id, 'status' => 'active'])
            ->assertJsonPath('data.limits.week.amount.minor', 500_00)
            ->assertJsonPath('data.pause', ['active' => true, 'ends_at' => now()->addDays(7)->toIso8601String()])
            ->assertJsonPath('data.restrictions.0.type', 'pause')
            ->assertJsonPath('data.restrictions.0.lifted', null)
            ->assertJsonPath('data.events.0.type', 'pause.started')
            ->assertJsonPath('data.events.1.type', 'limit.lowered');

        $log = AuditLog::where('action', AuditAction::ResponsibleGamingViewed)->sole();
        $this->assertSame($compliance->id, $log->actor_id);
        $this->assertSame((string) $this->player->id, $log->subject_id);
    }

    public function test_an_exclusion_cannot_be_reopened_before_its_end(): void
    {
        $this->excludePlayer('6m');
        $this->travel(5)->months();

        $this->reopen()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'exclusion_not_ended')
            ->assertJsonPath('error.details.ends_at', now()->subMonths(5)->addMonths(6)->toIso8601String());

        $this->assertSame(AccountStatus::Excluded, $this->player->fresh()->status);
        $this->assertNull(ResponsibleGamingRestriction::sole()->lifted_at);
        $this->assertSame(0, AuditLog::where('action', AuditAction::UserSelfExclusionEnded)->count());
    }

    public function test_reopening_an_ended_exclusion_reactivates_the_account_with_a_full_trace(): void
    {
        $this->excludePlayer('6m');
        $this->travel(6)->months();
        $this->travel(1)->minutes();
        $compliance = $this->admin(RoleSlug::Compliance);

        $this->reopen($compliance)
            ->assertOk()
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.restrictions.0.type', 'self_exclusion')
            ->assertJsonPath('data.restrictions.0.ended', true)
            ->assertJsonPath('data.restrictions.0.lifted.by', (string) $compliance->id)
            ->assertJsonPath('data.restrictions.0.lifted.reason', 'Demande écrite du joueur reçue le jour même');

        $this->assertSame(AccountStatus::Active, $this->player->fresh()->status);

        $event = ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::SelfExclusionLifted)->sole();
        $this->assertSame($compliance->id, $event->actor_id);

        $audit = AuditLog::where('action', AuditAction::UserSelfExclusionEnded)->sole();
        $this->assertSame($compliance->id, $audit->actor_id);
        $this->assertSame('Demande écrite du joueur reçue le jour même', $audit->reason);
        $this->assertSame(['status' => 'excluded'], $audit->before);
        $this->assertSame(['status' => 'active'], $audit->after);

        // Le joueur peut de nouveau se connecter.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'motdepasse123'])
            ->assertOk();
    }

    public function test_a_player_who_is_not_excluded_cannot_be_reopened(): void
    {
        $this->reopen()->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');

        $this->excludePlayer();
        $this->travel(7)->months();
        $this->reopen()->assertOk();
        $this->reopen()->assertStatus(409); // déjà rouverte
    }

    public function test_reopening_requires_a_reason_and_an_idempotency_key(): void
    {
        $this->excludePlayer();
        $this->travel(7)->months();

        $this->reopen(reason: 'abc')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Compliance))
            ->postJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming/reopen", ['reason' => 'Demande du joueur'])
            ->assertStatus(428);

        $this->assertSame(AccountStatus::Excluded, $this->player->fresh()->status);
    }

    public function test_an_administrator_still_cannot_lift_it_through_the_user_api(): void
    {
        $this->excludePlayer();
        $this->travel(7)->months();

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->patchJson("/api/v1/admin/users/{$this->player->id}", ['status' => 'active', 'reason' => 'Contournement'], [
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->assertStatus(409);
    }
}
