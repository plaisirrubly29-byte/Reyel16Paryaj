<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Admin\Services\AlertService;
use App\Modules\Admin\Services\SystemStatusService;
use App\Modules\Betting\Enums\BetType;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class AdminSupervisionApiTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Modules\\Settlement\\Jobs\\SettleEvent']),
            'exception' => 'RuntimeException: mot de passe=secret',
            'failed_at' => now(),
        ]);
    }

    public function test_alerts_come_from_real_data_and_follow_permissions(): void
    {
        // Match commencé il y a 10 h, ticket toujours en cours : règlement en retard.
        $market = $this->market();
        $market->event->update(['starts_at' => now()->subHours(10)]);
        $this->select($this->ticket(), $market->load('event'));
        $this->failedJob();

        $keys = fn (RoleSlug $role) => collect($this->actingAsVerifiedAdmin($this->admin($role))
            ->getJson('/api/v1/admin/alerts')->assertOk()->json('data'))->pluck('key')->all();

        // Super Admin : alertes critiques d'abord. Le ticket, écrit directement en base, n'a pas d'écriture
        // de mise dans le ledger : le rapprochement le signale comme un vrai écart.
        $this->assertSame(['reconciliation', 'failed_jobs', 'settlement_overdue'], $keys(RoleSlug::SuperAdmin));
        // Support : voit les tickets, pas la supervision technique.
        $this->assertSame(['settlement_overdue'], $keys(RoleSlug::Support));
    }

    public function test_every_super_admin_is_alerted_when_a_role_changes(): void
    {
        $target = User::factory()->create();
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['finance'], 'reason' => 'Nouvelle comptable'],
                ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $alerts = fn (RoleSlug $role) => collect($this->actingAsVerifiedAdmin($this->admin($role))
            ->getJson('/api/v1/admin/alerts')->assertOk()->json('data'));

        $this->assertSame('1 changement(s) de rôles administratifs sur 72 h', $alerts(RoleSlug::SuperAdmin)->firstWhere('key', 'role_changes')['title']);
        $this->assertNull($alerts(RoleSlug::Support)->firstWhere('key', 'role_changes'));

        $this->travel(73)->hours();
        $this->assertNull($alerts(RoleSlug::SuperAdmin)->firstWhere('key', 'role_changes'));
    }

    /** Revue P8-02, F12 : échecs de connexion répétés sur un compte, ou depuis une adresse IP, en 1 h. */
    public function test_repeated_login_failures_raise_a_security_alert(): void
    {
        $target = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);
        $fail = fn (string $phone, string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['phone' => $phone, 'password' => 'mauvais-mdp1'])->assertStatus(422);
        $alert = fn (RoleSlug $role) => collect($this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->actingAsVerifiedAdmin($this->admin($role))
            ->getJson('/api/v1/admin/alerts')->assertOk()->json('data'))->firstWhere('key', 'login_failures');

        // Un même compte, depuis des adresses différentes : alerte au 10e échec.
        for ($i = 1; $i < AlertService::LOGIN_FAILURES_PER_ACCOUNT; $i++) {
            $fail($target->phone, "10.0.0.{$i}");
        }
        $this->assertNull($alert(RoleSlug::SuperAdmin));

        $fail($target->phone, '10.0.0.99');
        $this->assertSame('Échecs de connexion répétés : 1 compte(s), 0 adresse(s) IP sur 60 min', $alert(RoleSlug::SuperAdmin)['title']);
        $this->assertNull($alert(RoleSlug::Support)); // sans audit.view

        // Une heure plus tard, l'alerte disparaît.
        $this->travel(61)->minutes();
        $this->assertNull($alert(RoleSlug::SuperAdmin));

        // Une même adresse, des numéros tous différents (comptes inexistants) : alerte au 30e échec. Le limiteur
        // « login » autorise 20 essais par minute et par IP : deux vagues, à une minute d'intervalle.
        for ($i = 0; $i < AlertService::LOGIN_FAILURES_PER_IP; $i++) {
            if ($i === 20) {
                $this->travel(61)->seconds();
            }
            $fail(sprintf('3800%04d', $i), '10.9.9.9');
        }
        $this->assertSame('Échecs de connexion répétés : 0 compte(s), 1 adresse(s) IP sur 60 min', $alert(RoleSlug::SuperAdmin)['title']);
    }

    public function test_no_alert_when_everything_is_fine(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->getJson('/api/v1/admin/alerts')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_system_status_reports_the_scheduler_and_never_leaks_secrets(): void
    {
        config(['sports_data.theodds.key' => 'cle-api-tres-secrete']);
        $this->failedJob();
        $admin = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin));

        $admin->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('data.scheduler.running', false)
            ->assertJsonPath('data.queue.failed_24h', 1)
            ->assertJsonPath('data.queue.recent_failures.0.job', 'App\\Modules\\Settlement\\Jobs\\SettleEvent')
            ->assertJsonPath('data.payments.integrated', false)
            ->assertJsonPath('data.backups.tracked', false);

        Cache::forever(SystemStatusService::HEARTBEAT_KEY, now()->toIso8601String());
        $content = $admin->getJson('/api/v1/admin/system')->assertJsonPath('data.scheduler.running', true)->getContent();

        $this->assertStringNotContainsString('cle-api-tres-secrete', $content);
        $this->assertStringNotContainsString('mot de passe', $content);
    }

    public function test_system_status_is_reserved_to_roles_that_read_settings(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))->getJson('/api/v1/admin/system')->assertForbidden();
    }

    public function test_exposure_takes_the_worst_outcome_of_a_market_not_the_sum(): void
    {
        $market = $this->market();
        $home = $this->ticket(null, ['stake_minor' => 100_00, 'potential_payout_minor' => 205_00]);
        $this->select($home, $market, 'home');
        $away = $this->ticket(null, ['stake_minor' => 50_00, 'potential_payout_minor' => 170_00, 'total_odds' => '3.40']);
        $this->select($away, $market, 'away');
        $combo = $this->ticket(null, ['type' => BetType::Multiple, 'potential_payout_minor' => 900_00]);
        $this->select($combo, $market, 'home');

        $event = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/risk/exposure')
            ->assertOk()
            ->assertJsonPath('data.total_events', 1)
            ->json('data.events.0');

        $this->assertSame(2, $event['single_tickets']);
        $this->assertSame(150_00, $event['single_stakes_minor']);
        // Pire cas : l'issue « home » gagne → 205 versés - 150 encaissés.
        $this->assertSame(55_00, $event['worst_case_minor']);
        $this->assertSame(1, $event['multiple_tickets']);
        $this->assertSame(900_00, $event['multiple_potential_payout_minor']);
    }

    public function test_global_search_respects_permissions(): void
    {
        $player = User::factory()->create(['name' => 'Ana Joueuse', 'phone' => '34123456']);
        $ticket = $this->ticket($player);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/search?q='.urlencode('+509 3412-3456'))
            ->assertOk()
            ->assertJsonPath('data.users.0.name', 'Ana Joueuse');

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/search?q='.strtolower(substr($ticket->reference, 0, 7)))
            ->assertJsonPath('data.tickets.0.reference', $ticket->reference);

        // Opérations : pas d'accès aux comptes, donc aucun résultat « users ».
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Operations))
            ->getJson('/api/v1/admin/search?q=Ana')
            ->assertOk()
            ->assertJsonMissingPath('data.users');

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/search?q=a')
            ->assertUnprocessable();
    }
}
