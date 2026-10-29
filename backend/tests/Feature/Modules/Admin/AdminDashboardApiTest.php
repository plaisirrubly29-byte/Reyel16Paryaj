<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Enums\MarketStatus;
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
        $this->travelTo(now()->setTime(12, 0));
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    public function test_super_admin_gets_every_section_with_daily_series(): void
    {
        $player = User::factory()->create();
        User::factory()->withStatus(AccountStatus::Suspended)->create(['created_at' => now()->subDays(20)]);

        $this->ticket($player, ['placed_at' => now()]);
        $this->ticket($player, ['placed_at' => now()->subDays(2), 'status' => BetTicketStatus::Won, 'payout_minor' => 205_00, 'settled_at' => now()]);
        $this->ticket($player, ['placed_at' => now()->subDays(3), 'status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'settled_at' => now()]);
        $this->ticket($player, ['placed_at' => now()->subDays(30), 'status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'settled_at' => now()]);
        $this->market()->update(['status' => MarketStatus::Suspended]);

        $response = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.currency', 'HTG')
            ->assertJsonPath('data.users.suspended', 1)
            ->assertJsonPath('data.betting.tickets_today', 1)
            ->assertJsonPath('data.betting.stake_today_minor', 100_00)
            ->assertJsonPath('data.betting.pending_count', 1)
            ->assertJsonPath('data.betting.pending_exposure_minor', 205_00)
            // Période de 14 jours : le ticket d'il y a 30 jours est exclu.
            ->assertJsonPath('data.finance.settled_stakes_minor', 200_00)
            ->assertJsonPath('data.finance.payouts_minor', 205_00)
            ->assertJsonPath('data.finance.gross_gaming_revenue_minor', -5_00)
            ->assertJsonPath('data.catalogue.markets_suspended', 1)
            ->assertJsonPath('data.catalogue.events_next_24h', 1); // match de market() : demain à la même heure

        $tickets = $response->json('data.betting.tickets');
        $this->assertCount(14, $tickets);
        $this->assertSame(['date' => now()->toDateString(), 'value' => 1], end($tickets));
        $this->assertSame(['date' => now()->subDays(13)->toDateString(), 'value' => 0], $tickets[0]);
        $this->assertSame(100_00, $response->json('data.betting.stakes_minor.11.value'));

        $breakdown = collect($response->json('data.betting.status_breakdown'))->pluck('count', 'status');
        $this->assertSame(['pending' => 1, 'won' => 1, 'lost' => 1, 'void' => 0], $breakdown->all());
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
