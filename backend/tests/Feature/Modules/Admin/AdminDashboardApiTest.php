<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Enums\MarketStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['app.display_timezone' => 'America/Port-au-Prince']);
        // 15 h UTC = 11 h à Port-au-Prince (UTC-4 en octobre).
        $this->travelTo(CarbonImmutable::parse('2026-10-29 15:00:00', 'UTC'));
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    public function test_figures_follow_the_period_and_local_days(): void
    {
        $player = User::factory()->create();
        User::factory()->withStatus(AccountStatus::Suspended)->create(['created_at' => now()->subDays(20)]);

        $today = $this->ticket($player, ['placed_at' => now()]);
        $this->select($today, $this->market());
        // 2 h UTC le 29 = 22 h le 28 à Port-au-Prince : compté le 28 en heure locale.
        $this->ticket($player, ['placed_at' => '2026-10-29 02:00:00', 'status' => BetTicketStatus::Won, 'payout_minor' => 205_00, 'settled_at' => now()]);
        $this->ticket($player, ['placed_at' => now()->subDays(3), 'status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'settled_at' => now()]);
        $this->ticket($player, ['placed_at' => now()->subDays(30), 'status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'settled_at' => now()->subDays(20)]);
        $this->market('autre')->update(['status' => MarketStatus::Suspended]);

        $response = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->getJson('/api/v1/admin/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.period', ['preset' => '7d', 'from' => '2026-10-23', 'to' => '2026-10-29', 'days' => 7, 'timezone' => 'America/Port-au-Prince'])
            ->assertJsonPath('data.users.suspended', 1)
            ->assertJsonPath('data.betting.tickets_placed', 3)
            ->assertJsonPath('data.betting.stakes_minor', 300_00)
            ->assertJsonPath('data.betting.tickets_settled', 2)
            ->assertJsonPath('data.betting.payouts_minor', 205_00)
            ->assertJsonPath('data.betting.pending_count', 1)
            ->assertJsonPath('data.betting.pending_exposure_minor', 205_00)
            ->assertJsonPath('data.betting.stakes_by_sport', [['sport' => 'soccer', 'name' => 'Football', 'tickets' => 1, 'stakes_minor' => 100_00]])
            ->assertJsonPath('data.finance.settled_stakes_minor', 200_00)
            ->assertJsonPath('data.finance.gross_gaming_revenue_minor', -5_00)
            ->assertJsonPath('data.finance.payments_available', false)
            ->assertJsonPath('data.catalogue.markets_suspended', 1);

        $tickets = collect($response->json('data.betting.tickets'))->pluck('value', 'date');
        $this->assertCount(7, $tickets);
        $this->assertSame(1, $tickets['2026-10-29']);
        $this->assertSame(1, $tickets['2026-10-28']);
        $this->assertSame(1, $tickets['2026-10-26']);
    }

    public function test_today_and_custom_periods(): void
    {
        $this->ticket(null, ['placed_at' => now()]);
        $this->ticket(null, ['placed_at' => '2026-10-29 02:00:00']); // la veille en heure locale
        $admin = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin));

        $admin->getJson('/api/v1/admin/dashboard?period=today')->assertJsonPath('data.betting.tickets_placed', 1);
        $admin->getJson('/api/v1/admin/dashboard?period=yesterday')->assertJsonPath('data.betting.tickets_placed', 1);
        $admin->getJson('/api/v1/admin/dashboard?period=custom&from=2026-10-28&to=2026-10-29')
            ->assertJsonPath('data.period.days', 2)
            ->assertJsonPath('data.betting.tickets_placed', 2);
    }

    public function test_invalid_periods_are_rejected(): void
    {
        $admin = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin));

        $admin->getJson('/api/v1/admin/dashboard?period=decade')->assertUnprocessable();
        $admin->getJson('/api/v1/admin/dashboard?period=custom')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['from', 'to']]]);
        $admin->getJson('/api/v1/admin/dashboard?period=custom&from=2026-10-29&to=2026-10-01')->assertUnprocessable();
        $admin->getJson('/api/v1/admin/dashboard?period=custom&from=2024-01-01&to=2026-10-29')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['to']]]);
    }

    public function test_support_does_not_receive_financial_figures(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['users', 'betting', 'catalogue']])
            ->assertJsonMissingPath('data.finance');
    }

    public function test_players_cannot_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }
}
