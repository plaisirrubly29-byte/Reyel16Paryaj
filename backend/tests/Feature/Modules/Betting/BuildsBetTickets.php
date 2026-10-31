<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\User;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Models\Team;
use App\Modules\Wallet\Models\Wallet;

/** Matchs, tickets et sélections de test, écrits directement en base (sans passer par POST /bets). */
trait BuildsBetTickets
{
    /** @param  array<string, string>  $odds  clé de sélection => cote */
    private function market(string $externalId = 'abc', string $key = 'h2h', ?string $line = null, array $odds = ['home' => '2.05', 'away' => '3.40']): Market
    {
        $sport = Sport::firstOrCreate(['slug' => 'soccer'], ['name' => 'Football']);
        $competition = Competition::firstOrCreate(
            ['provider' => 'theodds', 'external_key' => 'soccer_epl'],
            ['sport_id' => $sport->id, 'name' => 'Premier League'],
        );
        $event = Event::firstOrCreate(['provider' => 'theodds', 'external_id' => $externalId], [
            'competition_id' => $competition->id,
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Everton'])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Chelsea'])->id,
            'starts_at' => now()->addDay(),
        ]);

        $market = $event->markets()->create(['key' => $key, 'line' => $line, 'odds_version' => 1, 'odds_updated_at' => now()]);
        foreach ($odds as $selectionKey => $value) {
            $market->selections()->create(['key' => $selectionKey, 'name' => ucfirst($selectionKey), 'odds' => $value]);
        }

        return $market;
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(?User $user = null, array $attributes = []): BetTicket
    {
        $user ??= User::factory()->create();

        return BetTicket::create([
            'user_id' => $user->id,
            'wallet_id' => Wallet::firstOrCreate(['user_id' => $user->id, 'currency' => 'HTG'])->id,
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
}
