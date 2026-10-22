<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\User;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Models\Team;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class BetTicketSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function market(): Market
    {
        $sport = Sport::firstOrCreate(['slug' => 'soccer'], ['name' => 'Football']);
        $competition = Competition::firstOrCreate(
            ['provider' => 'theodds', 'external_key' => 'soccer_epl'],
            ['sport_id' => $sport->id, 'name' => 'Premier League'],
        );
        $event = Event::create([
            'competition_id' => $competition->id,
            'provider' => 'theodds',
            'external_id' => 'abc',
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Everton'])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Chelsea'])->id,
            'starts_at' => now()->addDay(),
        ]);

        $market = $event->markets()->create(['key' => 'h2h', 'odds_version' => 1, 'odds_updated_at' => now()]);
        $market->selections()->create(['key' => 'home', 'name' => 'Everton', 'odds' => '2.05']);
        $market->selections()->create(['key' => 'away', 'name' => 'Chelsea', 'odds' => '3.40']);

        return $market;
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes = []): BetTicket
    {
        $user = User::factory()->create();

        return BetTicket::create([
            'user_id' => $user->id,
            'wallet_id' => Wallet::create(['user_id' => $user->id, 'currency' => 'HTG'])->id,
            'type' => BetType::Single,
            'stake_minor' => 100_00,
            'currency' => 'HTG',
            'total_odds' => '2.05',
            'potential_payout_minor' => 205_00,
            'rules_version' => 'test-1',
            'rules_snapshot' => ['min_stake_minor' => 10_00, 'odds_change_policy' => 'reject'],
            ...$attributes,
        ]);
    }

    private function select(BetTicket $ticket, Market $market, string $key = 'home'): BetSelection
    {
        $selection = $market->selections()->where('key', $key)->firstOrFail();

        return BetSelection::create([
            'bet_ticket_id' => $ticket->id,
            'event_id' => $market->event_id,
            'market_id' => $market->id,
            'selection_id' => $selection->id,
            'event_name' => 'Everton – Chelsea',
            'event_starts_at' => $market->event->starts_at,
            'market_key' => $market->key,
            'market_line' => $market->line,
            'selection_key' => $selection->key,
            'selection_name' => $selection->name,
            'odds' => $selection->odds,
            'odds_version' => $market->odds_version,
        ]);
    }

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
        $this->ticket(['reference' => 'R16-AAAAAAAAAA']);

        $this->expectException(QueryException::class);
        $this->ticket(['reference' => 'R16-AAAAAAAAAA']);
    }

    public function test_amounts_are_integers_and_odds_exact_strings(): void
    {
        $market = $this->market();
        $ticket = $this->ticket(['total_odds' => '2.05']);
        $this->select($ticket, $market);

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
        $market = $this->market();
        $ticket = $this->ticket();
        $selection = $this->select($ticket, $market);

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
        $ticket = $this->ticket(['type' => BetType::Multiple]);
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
        $this->assertSame('Everton', $selection->selection_name);
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
