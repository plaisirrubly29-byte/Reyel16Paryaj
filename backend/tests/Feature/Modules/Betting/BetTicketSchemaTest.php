<?php

namespace Tests\Feature\Modules\Betting;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class BetTicketSchemaTest extends TestCase
{
    use BuildsBetTickets, RefreshDatabase;

    public function test_a_readable_reference_is_generated_and_defaults_apply(): void
    {
        $ticket = $this->ticket()->fresh();

        $this->assertMatchesRegularExpression('/^R16-[2-9A-HJKMNP-Z]{10}$/', $ticket->reference);
        $this->assertSame(BetTicketStatus::Pending, $ticket->status);
        $this->assertNotNull($ticket->placed_at);
        $this->assertNull($ticket->payout());
    }

    public function test_reference_is_unique_in_database(): void
    {
        $this->ticket(attributes: ['reference' => 'R16-AAAAAAAAAA']);

        $this->expectException(QueryException::class);
        $this->ticket(attributes: ['reference' => 'R16-AAAAAAAAAA']);
    }

    public function test_amounts_are_integers_and_odds_exact_strings(): void
    {
        $ticket = $this->ticket(attributes: ['total_odds' => '2.05']);
        $this->select($ticket, $this->market());

        $ticket = $ticket->fresh();
        $this->assertSame(100_00, $ticket->stake()->minor);
        $this->assertSame(205_00, $ticket->potentialPayout()->minor);
        $this->assertSame('2.050', $ticket->total_odds);
        $this->assertSame('2.050', $ticket->selections->first()->odds);
        $this->assertSame(['min_stake_minor' => 10_00, 'odds_change_policy' => 'reject'], $ticket->rules_snapshot);
    }

    public function test_no_column_of_the_betting_tables_is_a_float(): void
    {
        foreach (['bet_tickets', 'bet_selections'] as $table) {
            $types = collect(Schema::getColumns($table))->pluck('type_name')->map(fn ($type) => strtolower($type));

            $this->assertEmpty($types->intersect(['float', 'double', 'real', 'double precision']), "Colonne flottante dans {$table}");
        }
    }

    public function test_accepted_terms_of_a_ticket_are_immutable(): void
    {
        $ticket = $this->ticket();

        $this->expectException(LogicException::class);
        $ticket->update(['stake_minor' => 1_000_000_00]);
    }

    public function test_settlement_can_still_update_status_and_payout(): void
    {
        $ticket = $this->ticket();
        $selection = $this->select($ticket, $this->market());

        $selection->update(['status' => BetSelectionStatus::Won, 'settled_at' => now()]);
        $ticket->update(['status' => BetTicketStatus::Won, 'payout_minor' => 205_00, 'settled_at' => now()]);

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(205_00, $ticket->fresh()->payout()->minor);
        $this->assertSame(BetSelectionStatus::Won, $selection->fresh()->status);
    }

    public function test_accepted_odds_of_a_selection_are_immutable(): void
    {
        $selection = $this->select($this->ticket(), $this->market());

        $this->expectException(LogicException::class);
        $selection->update(['odds' => '9.99']);
    }

    public function test_tickets_and_selections_cannot_be_deleted(): void
    {
        $ticket = $this->ticket();
        $selection = $this->select($ticket, $this->market());

        try {
            $selection->delete();
            $this->fail('Une sélection de ticket a été supprimée.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $ticket->delete();
    }

    public function test_only_one_selection_per_market_in_a_ticket(): void
    {
        $market = $this->market();
        $ticket = $this->ticket(attributes: ['type' => BetType::Multiple]);
        $this->select($ticket, $market, 'home');

        $this->expectException(QueryException::class);
        $this->select($ticket, $market, 'away');
    }

    public function test_the_snapshot_survives_catalogue_changes(): void
    {
        $market = $this->market();
        $selection = $this->select($this->ticket(), $market);

        // La cote bouge puis le fournisseur retire la sélection : le ticket garde ce qui a été accepté.
        $market->selections()->where('key', 'home')->update(['odds' => '1.50']);
        $market->selections()->where('key', 'home')->delete();

        $selection = $selection->fresh();
        $this->assertNull($selection->selection_id);
        $this->assertSame('home', $selection->selection_key);
        $this->assertSame('Home', $selection->selection_name);
        $this->assertSame('2.050', $selection->odds);
        $this->assertSame(1, $selection->odds_version);
    }

    public function test_a_player_or_event_with_tickets_cannot_be_hard_deleted(): void
    {
        $market = $this->market();
        $ticket = $this->ticket();
        $this->select($ticket, $market);

        try {
            $market->event->delete();
            $this->fail('Un match avec des paris a été supprimé.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        $ticket->user->delete();
    }
}
