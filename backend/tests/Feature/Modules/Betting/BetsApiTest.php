<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\User;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetsApiTest extends TestCase
{
    use BuildsBetTickets, RefreshDatabase;

    public function test_tickets_require_authentication(): void
    {
        $this->getJson('/api/v1/bets')->assertUnauthorized();
        $this->getJson('/api/v1/bets/1')->assertUnauthorized();
    }

    public function test_a_player_sees_the_receipt_of_their_ticket(): void
    {
        $player = User::factory()->create();
        $h2h = $this->market();
        $totals = $this->market(key: 'totals', line: '2.5', odds: ['over' => '1.90', 'under' => '1.95']);
        $ticket = $this->ticket($player, ['type' => BetType::Multiple, 'total_odds' => '3.895', 'potential_payout_minor' => 389_50]);
        $this->select($ticket, $h2h, 'home');
        $this->select($ticket, $totals, 'over');

        $this->actingAs($player)->getJson("/api/v1/bets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', $ticket->reference)
            ->assertJsonPath('data.type', 'multiple')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stake', ['minor' => 100_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.total_odds', '3.895')
            ->assertJsonPath('data.potential_payout', ['minor' => 389_50, 'currency' => 'HTG'])
            ->assertJsonPath('data.payout', null)
            ->assertJsonCount(2, 'data.selections')
            ->assertJsonPath('data.selections.0.event.name', 'Everton – Chelsea')
            ->assertJsonPath('data.selections.0.market', ['key' => 'h2h', 'name' => 'Résultat du match (1X2)', 'line' => null])
            ->assertJsonPath('data.selections.0.selection', ['key' => 'home', 'label' => '1', 'name' => 'Home'])
            ->assertJsonPath('data.selections.0.odds', '2.05')
            ->assertJsonPath('data.selections.1.market.name', 'Nombre de buts')
            ->assertJsonPath('data.selections.1.selection.label', '+2.5')
            ->assertJsonPath('data.selections.1.odds', '1.90')
            ->assertJsonMissingPath('data.rules_snapshot')
            ->assertJsonMissingPath('data.wallet_id');
    }

    public function test_the_receipt_shows_the_accepted_odds_not_the_current_ones(): void
    {
        $player = User::factory()->create();
        $market = $this->market();
        $ticket = $this->ticket($player);
        $this->select($ticket, $market, 'home');

        $market->selections()->where('key', 'home')->update(['odds' => '1.40']);

        $this->actingAs($player)->getJson("/api/v1/bets/{$ticket->id}")
            ->assertJsonPath('data.selections.0.odds', '2.05');
    }

    public function test_a_settled_ticket_shows_its_payout(): void
    {
        $player = User::factory()->create();
        $ticket = $this->ticket($player);
        $selection = $this->select($ticket, $this->market(), 'home');
        $selection->update(['status' => BetSelectionStatus::Won, 'settled_at' => now()]);
        $ticket->update(['status' => BetTicketStatus::Won, 'payout_minor' => 205_00, 'settled_at' => now()]);

        $this->actingAs($player)->getJson("/api/v1/bets/{$ticket->id}")
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.payout', ['minor' => 205_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.selections.0.status', 'won');
    }

    public function test_the_list_is_paginated_newest_first_and_filterable(): void
    {
        $player = User::factory()->create();
        $first = $this->ticket($player);
        $this->ticket($player, ['status' => BetTicketStatus::Lost, 'payout_minor' => 0]);
        $last = $this->ticket($player);

        $this->actingAs($player)->getJson('/api/v1/bets?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $last->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($player)->getJson('/api/v1/bets?per_page=2&page=2')
            ->assertJsonPath('data.0.id', (string) $first->id);

        $this->actingAs($player)->getJson('/api/v1/bets?status=lost')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'lost')
            ->assertJsonPath('data.0.payout', ['minor' => 0, 'currency' => 'HTG']);
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/bets?status=paid')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_player_never_sees_another_players_tickets(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceTicket = $this->ticket($alice);
        $this->select($aliceTicket, $this->market(), 'home');
        $this->ticket($bob);

        $this->actingAs($bob)->getJson('/api/v1/bets')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['reference' => $aliceTicket->reference]);

        // IDOR : même réponse que pour un ticket inexistant, sans révéler qu'il existe.
        $this->actingAs($bob)->getJson("/api/v1/bets/{$aliceTicket->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissing(['reference' => $aliceTicket->reference]);
        $this->actingAs($bob)->getJson('/api/v1/bets/999999')->assertNotFound();
        $this->actingAs($bob)->getJson('/api/v1/bets/abc')->assertNotFound();
    }

    public function test_a_suspended_player_cannot_read_tickets(): void
    {
        $player = User::factory()->withStatus(AccountStatus::Suspended)->create();
        $this->ticket($player);

        $this->actingAs($player)->getJson('/api/v1/bets')->assertStatus(423);
    }

    public function test_the_api_does_not_accept_ticket_changes(): void
    {
        $player = User::factory()->create();
        $ticket = $this->ticket($player);

        $this->actingAs($player)->patchJson("/api/v1/bets/{$ticket->id}", ['stake_minor' => 1])->assertStatus(405);
        $this->actingAs($player)->deleteJson("/api/v1/bets/{$ticket->id}")->assertStatus(405);
    }
}
