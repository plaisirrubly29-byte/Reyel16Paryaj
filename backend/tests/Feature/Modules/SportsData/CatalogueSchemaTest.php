<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $externalId = 'abc'): Event
    {
        $sport = Sport::firstOrCreate(['slug' => 'soccer'], ['name' => 'Football']);
        $competition = Competition::firstOrCreate(
            ['provider' => 'theodds', 'external_key' => 'soccer_epl'],
            ['sport_id' => $sport->id, 'name' => 'Premier League'],
        );

        return Event::create([
            'competition_id' => $competition->id,
            'provider' => 'theodds',
            'external_id' => $externalId,
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Everton'])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sport->id, 'name' => 'Chelsea'])->id,
            'starts_at' => now()->addDay(),
        ]);
    }

    public function test_an_event_is_unique_per_provider_reference(): void
    {
        $this->event('abc');

        $this->expectException(QueryException::class);
        $this->event('abc');
    }

    public function test_defaults_and_odds_are_exact_strings(): void
    {
        $event = $this->event();
        $market = $event->markets()->create(['key' => 'h2h']);
        $selection = $market->selections()->create(['key' => 'home', 'name' => 'Everton', 'odds' => '2.95']);

        $this->assertSame(EventStatus::Scheduled, $event->fresh()->status);
        $this->assertSame(MarketStatus::Open, $market->fresh()->status);
        $this->assertSame(1, $market->fresh()->odds_version);
        $this->assertSame('2.950', $selection->fresh()->odds);
    }

    public function test_one_market_per_key_and_one_selection_per_key(): void
    {
        $market = $this->event()->markets()->create(['key' => 'h2h']);
        $market->selections()->create(['key' => 'home', 'name' => 'Everton', 'odds' => '2.95']);

        $this->expectException(QueryException::class);
        $market->selections()->create(['key' => 'home', 'name' => 'Everton', 'odds' => '3.00']);
    }
}
