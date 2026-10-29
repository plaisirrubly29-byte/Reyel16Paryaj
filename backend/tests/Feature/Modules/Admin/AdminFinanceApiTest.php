<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class AdminFinanceApiTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private LedgerService $ledger;

    private User $player;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->ledger = app(LedgerService::class);
        $this->player = User::factory()->create();
        $this->wallet = $this->ledger->walletFor($this->player);
        $this->ledger->creditSimulated($this->wallet, Money::ofMinor(1_000_00, 'HTG'), 'test:credit');
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    /** Ticket passé comme par POST /bets puis réglé comme par SettlementService (mêmes écritures). */
    private function settledTicket(BetTicketStatus $status): BetTicket
    {
        $payout = match ($status) {
            BetTicketStatus::Won => 205_00,
            BetTicketStatus::Void => 100_00,
            default => 0,
        };
        $ticket = $this->ticket($this->player);
        $this->ledger->reserveStake($this->wallet, Money::ofMinor(100_00, 'HTG'), "bet:{$ticket->id}:stake", (string) $ticket->id);

        if ($status !== BetTicketStatus::Pending) {
            match ($status) {
                BetTicketStatus::Won => $this->ledger->payout($this->wallet, Money::ofMinor($payout, 'HTG'), "settlement:{$ticket->id}:r1:payout", (string) $ticket->id),
                BetTicketStatus::Void => $this->ledger->refund($this->wallet, Money::ofMinor($payout, 'HTG'), "settlement:{$ticket->id}:r1:refund", (string) $ticket->id),
                default => null,
            };
            $ticket->update(['status' => $status, 'payout_minor' => $payout, 'settled_at' => now()]);
        }

        return $ticket;
    }

    public function test_finance_role_is_separate_from_support_and_operations(): void
    {
        foreach ([RoleSlug::Support, RoleSlug::Operations] as $role) {
            $admin = $this->actingAsVerifiedAdmin($this->admin($role));
            $admin->getJson('/api/v1/admin/ledger')->assertForbidden();
            $admin->getJson('/api/v1/admin/finance/reconciliation')->assertForbidden();
            $admin->getJson('/api/v1/admin/finance/corrections')->assertForbidden();
        }

        foreach ([RoleSlug::Finance, RoleSlug::Compliance, RoleSlug::SuperAdmin] as $role) {
            $admin = $this->actingAsVerifiedAdmin($this->admin($role));
            $admin->getJson('/api/v1/admin/ledger')->assertOk();
            $admin->getJson('/api/v1/admin/finance/reconciliation')->assertOk();
            $admin->getJson('/api/v1/admin/finance/corrections')->assertOk();
        }
    }

    public function test_ledger_lists_entries_with_filters_and_correctability(): void
    {
        $ticket = $this->settledTicket(BetTicketStatus::Won);
        $this->ledger->creditSimulated($this->ledger->walletFor(User::factory()->create()), Money::ofMinor(50_00, 'HTG'), 'test:other');
        $finance = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Finance));

        $finance->getJson('/api/v1/admin/ledger')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 4);

        $finance->getJson("/api/v1/admin/ledger?user_id={$this->player->id}")->assertJsonCount(3, 'data');
        $finance->getJson("/api/v1/admin/ledger?ticket_id={$ticket->id}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'payout')
            ->assertJsonPath('data.0.related', ['type' => 'bet', 'id' => (string) $ticket->id])
            ->assertJsonPath('data.0.correctable', false);

        $finance->getJson('/api/v1/admin/ledger?type=simulated_credit&reference=test:credit')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', (string) $this->player->id)
            ->assertJsonPath('data.0.balance_after', ['minor' => 1_000_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.0.reversed_by_id', null)
            ->assertJsonPath('data.0.correctable', true);
    }

    public function test_reconciliation_finds_no_discrepancy_in_consistent_data(): void
    {
        foreach (BetTicketStatus::cases() as $status) {
            $this->settledTicket($status);
        }

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Finance))
            ->getJson('/api/v1/admin/finance/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.checked', ['wallets' => 1, 'ledger_entries' => 7, 'tickets' => 4])
            ->assertJsonPath('data.discrepancies_count', 0)
            ->assertJsonPath('data.discrepancies', [])
            ->assertJsonPath('data.truncated', false)
            ->assertJsonFragment(['currency' => 'HTG', 'type' => 'stake', 'entries' => 4, 'sum' => ['minor' => -400_00, 'currency' => 'HTG']])
            ->assertJsonFragment(['currency' => 'HTG', 'type' => 'payout', 'entries' => 1, 'sum' => ['minor' => 205_00, 'currency' => 'HTG']]);
    }

    public function test_reconciliation_reports_each_kind_of_discrepancy(): void
    {
        $ticket = $this->settledTicket(BetTicketStatus::Pending);
        // Ticket gagné dont le gain n'a jamais été versé.
        $unpaid = $this->settledTicket(BetTicketStatus::Pending);
        $unpaid->update(['status' => BetTicketStatus::Won, 'payout_minor' => 205_00, 'settled_at' => now()]);

        // Écritures écrites hors LedgerService : solde mémorisé faux, et ticket inexistant.
        $balance = (int) $this->wallet->entries()->sum('amount_minor');
        $broken = LedgerEntry::create([
            'wallet_id' => $this->wallet->id, 'type' => LedgerEntryType::SimulatedCredit, 'amount_minor' => 10_00,
            'currency' => 'HTG', 'balance_after_minor' => $balance + 99_00, 'reference' => 'test:broken',
        ]);
        $orphan = LedgerEntry::create([
            'wallet_id' => $this->wallet->id, 'type' => LedgerEntryType::Refund, 'amount_minor' => 5_00, 'currency' => 'HTG',
            'balance_after_minor' => $balance + 99_00 + 5_00, 'reference' => 'test:orphan', 'related_type' => 'bet', 'related_id' => '999999',
        ]);

        $response = $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Finance))
            ->getJson('/api/v1/admin/finance/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.discrepancies_count', 3);

        $this->assertSame([
            [
                'kind' => 'balance_chain', 'wallet_id' => (string) $this->wallet->id, 'ledger_entry_id' => (string) $broken->id,
                'expected' => ['minor' => $balance + 10_00, 'currency' => 'HTG'], 'actual' => ['minor' => $balance + 99_00, 'currency' => 'HTG'],
            ],
            [
                'kind' => 'ticket_amount', 'ticket_id' => (string) $unpaid->id, 'reference' => $unpaid->reference, 'status' => 'won',
                'expected' => ['minor' => 105_00, 'currency' => 'HTG'], 'actual' => ['minor' => -100_00, 'currency' => 'HTG'],
            ],
            [
                'kind' => 'orphan_entry', 'wallet_id' => (string) $this->wallet->id, 'ledger_entry_id' => (string) $orphan->id,
                'related' => ['type' => 'bet', 'id' => '999999'],
            ],
        ], $response->json('data.discrepancies'));

        $this->assertNotContains((string) $ticket->id, array_column($response->json('data.discrepancies'), 'ticket_id'));
    }
}
