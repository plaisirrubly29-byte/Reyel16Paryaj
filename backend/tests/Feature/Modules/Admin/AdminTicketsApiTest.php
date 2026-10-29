<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class AdminTicketsApiTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create(['name' => 'Ana Joueuse', 'phone' => '34123456']);
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    public function test_support_lists_tickets_with_minimal_player_data(): void
    {
        $ticket = $this->ticket($this->player);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $ticket->reference)
            ->assertJsonPath('data.0.user', ['id' => (string) $this->player->id, 'name' => 'Ana Joueuse'])
            ->assertJsonPath('data.0.stake', ['minor' => 100_00, 'currency' => 'HTG'])
            ->assertJsonMissingPath('data.0.user.phone')
            ->assertJsonMissingPath('data.0.selections')
            ->assertJsonMissingPath('data.0.ledger_entries');
    }

    public function test_search_by_reference_status_player_and_date(): void
    {
        $target = $this->ticket($this->player);
        $this->ticket($this->player, ['status' => BetTicketStatus::Lost, 'payout_minor' => 0, 'settled_at' => now()]);
        $this->ticket(null, ['placed_at' => now()->subDays(10)]);
        $support = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support));

        // Référence dictée : casse et espaces ignorés.
        $support->getJson('/api/v1/admin/tickets?reference='.urlencode(' '.strtolower($target->reference)))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $target->id);

        $support->getJson('/api/v1/admin/tickets?status=lost')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'lost');
        $support->getJson("/api/v1/admin/tickets?user_id={$this->player->id}")->assertJsonCount(2, 'data');

        $tenDaysAgo = now()->subDays(10)->toDateString();
        $support->getJson("/api/v1/admin/tickets?placed_from={$tenDaysAgo}&placed_to={$tenDaysAgo}")->assertJsonCount(1, 'data');
        $support->getJson('/api/v1/admin/tickets?placed_from='.now()->toDateString())->assertJsonCount(2, 'data');

        $support->getJson('/api/v1/admin/tickets?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson('/api/v1/admin/tickets?status=paid&placed_from=2026-10-20&placed_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['status', 'placed_to']]]);
    }

    public function test_detail_shows_selections_and_rules_but_ledger_only_with_ledger_view(): void
    {
        $ticket = $this->ticket($this->player);
        $this->select($ticket, $this->market());
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ticket->wallet, Money::ofMinor(500_00, 'HTG'), 'test:credit');
        $ledger->reserveStake($ticket->wallet, Money::ofMinor(100_00, 'HTG'), "bet:{$ticket->id}:stake", (string) $ticket->id);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson("/api/v1/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.selections')
            ->assertJsonPath('data.rules_snapshot.odds_change_policy', 'reject')
            ->assertJsonMissingPath('data.ledger_entries');

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Finance))
            ->getJson("/api/v1/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ledger_entries')
            ->assertJsonPath('data.ledger_entries.0.type', 'stake')
            ->assertJsonPath('data.ledger_entries.0.amount.minor', -100_00)
            ->assertJsonPath('data.ledger_entries.0.correctable', false);
    }

    public function test_every_admin_role_can_consult_tickets_but_not_a_player(): void
    {
        $ticket = $this->ticket($this->player);

        foreach (RoleSlug::cases() as $role) {
            $this->actingAsVerifiedAdmin($this->admin($role))->getJson("/api/v1/admin/tickets/{$ticket->id}")->assertOk();
        }

        $this->actingAs($this->player)->getJson('/api/v1/admin/tickets')->assertForbidden();
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))->getJson('/api/v1/admin/tickets/999999')->assertNotFound();
    }
}
