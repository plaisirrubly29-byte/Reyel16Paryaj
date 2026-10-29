<?php

namespace Tests\Feature\Modules\Audit;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_records_actor_subject_before_after_and_reason(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $player = User::factory()->create();

        $log = app(AuditLogger::class)->record(
            AuditAction::UserSuspended,
            $player,
            before: ['status' => 'active'],
            after: ['status' => 'suspended'],
            reason: 'Fraude suspectée',
            actor: $admin,
        );

        $log = $log->fresh();
        $this->assertSame(AuditAction::UserSuspended, $log->action);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('admin@example.com', $log->actor_email);
        $this->assertSame((string) $player->id, $log->subject_id);
        $this->assertSame(['status' => 'active'], $log->before);
        $this->assertSame(['status' => 'suspended'], $log->after);
        $this->assertSame('Fraude suspectée', $log->reason);
    }

    public function test_sensitive_action_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AuditLogger::class)->record(AuditAction::UserSuspended, User::factory()->create(), reason: '   ');
    }

    public function test_log_is_append_only(): void
    {
        $log = app(AuditLogger::class)->record(AuditAction::AuditViewed);

        try {
            $log->update(['reason' => 'falsifié']);
            $this->fail('Une entrée d\'audit a pu être modifiée.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Une entrée d\'audit a pu être supprimée.');
        } catch (LogicException) {
        }

        $this->assertNull($log->fresh()->reason);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_history_survives_the_deletion_of_the_actor(): void
    {
        $admin = User::factory()->create(['email' => 'parti@example.com']);
        app(AuditLogger::class)->record(AuditAction::AuditViewed, actor: $admin);

        $admin->delete();

        $this->assertSame('parti@example.com', AuditLog::firstOrFail()->actor_email);
    }

    public function test_role_change_is_audited_with_reason(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $target = User::factory()->withRole(RoleSlug::Support)->create();

        $this->actingAsVerifiedAdmin($admin)->putJson("/api/v1/admin/users/{$target->id}/roles", [
            'roles' => ['finance'],
            'reason' => 'Mutation au service Finance',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $log = AuditLog::where('action', AuditAction::RolesUpdated)->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['roles' => ['support']], $log->before);
        $this->assertSame(['roles' => ['finance']], $log->after);
        $this->assertSame('Mutation au service Finance', $log->reason);
    }

    public function test_refused_role_change_is_not_audited(): void
    {
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->actingAsVerifiedAdmin($admin)->putJson("/api/v1/admin/users/{$admin->id}/roles", [
            'roles' => [],
            'reason' => 'Tentative',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_log_listing_is_restricted_filterable_and_itself_audited(): void
    {
        $compliance = User::factory()->withRole(RoleSlug::Compliance)->create();
        $operations = User::factory()->withRole(RoleSlug::Operations)->create();
        $player = User::factory()->create();

        $logger = app(AuditLogger::class);
        $logger->record(AuditAction::UserSuspended, $player, reason: 'Motif A', actor: $compliance);
        $logger->record(AuditAction::RolesUpdated, $operations, reason: 'Motif B', actor: $compliance);

        $this->actingAsVerifiedAdmin($operations)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->actingAs($player)->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $this->actingAsVerifiedAdmin($compliance)->getJson('/api/v1/admin/audit-logs?action=user.suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Motif A')
            ->assertJsonPath('data.0.subject.id', (string) $player->id)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);

        $this->assertSame(1, AuditLog::where('action', AuditAction::AuditViewed)->where('actor_id', $compliance->id)->count());
    }

    public function test_only_a_safe_request_id_is_recorded(): void
    {
        $compliance = User::factory()->withRole(RoleSlug::Compliance)->create();
        $this->actingAsVerifiedAdmin($compliance);

        $this->getJson('/api/v1/admin/audit-logs', ['X-Request-Id' => str_repeat('a', 64)])->assertOk();
        $this->assertSame(str_repeat('a', 64), AuditLog::latest('id')->first()->request_id);

        // Trop long pour la colonne varchar(64) (500 sur PostgreSQL), ou dangereux dans les logs : non repris ;
        // l'audit garde l'identifiant généré par AssignRequestId, le même que celui de la réponse et des logs.
        foreach ([str_repeat('a', 65), "abc12345\ninjection", 'court'] as $unsafe) {
            $response = $this->getJson('/api/v1/admin/audit-logs', ['X-Request-Id' => $unsafe])->assertOk();
            $recorded = AuditLog::latest('id')->first()->request_id;
            $this->assertTrue(Str::isUuid($recorded), "Valeur reprise : {$unsafe}");
            $this->assertSame($response->headers->get('X-Request-Id'), $recorded);
        }

        $this->assertSame(4, AuditLog::where('action', AuditAction::AuditViewed)->count());
    }
}
