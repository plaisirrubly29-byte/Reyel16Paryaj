<?php

namespace Tests\Feature\Modules\Catalogue;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 300]);
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        $sync = app(CatalogueSyncService::class);
        $sync->sync('soccer_epl');     // 2 matchs (Arsenal–Chelsea, Liverpool–Everton)
        $sync->sync('basketball_nba'); // 1 match
    }

    private function event(string $externalId): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    public function test_sports_list_open_competitions_with_counts(): void
    {
        $this->getJson('/api/v1/sports')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'soccer')
            ->assertJsonPath('data.0.name', 'Football')
            ->assertJsonPath('data.0.events_count', 2)
            ->assertJsonPath('data.0.competitions.0.name', 'Premier League')
            ->assertJsonPath('data.1.slug', 'basketball')
            ->assertJsonPath('data.1.events_count', 1);
    }

    public function test_events_are_listed_by_start_time_with_inline_1x2_odds(): void
    {
        $response = $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(3, 'data');

        $first = $response->json('data.0');
        $this->assertSame('Arsenal', $first['home_team']);
        $this->assertSame('Football', $first['sport']['name']);
        // La liste ne montre que le 1X2, même quand le match a un plus/moins.
        $this->assertSame([['h2h'], ['h2h'], ['h2h']], array_map(fn ($e) => array_column($e['markets'], 'key'), $response->json('data')));
        $this->assertNull($first['markets'][0]['line']);
        $this->assertSame(1, $first['markets'][0]['odds_version']);
        $this->assertSame(
            [['1', 'Arsenal', '2.10'], ['X', 'Match nul', '3.25'], ['2', 'Chelsea', '3.40']],
            array_map(fn ($s) => [$s['label'], $s['name'], $s['odds']], $first['markets'][0]['selections']),
        );

        $starts = array_column($response->json('data'), 'starts_at');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    public function test_odds_are_always_strings(): void
    {
        $odds = $this->getJson('/api/v1/events')->json('data.0.markets.0.selections.0.odds');

        $this->assertIsString($odds);
    }

    public function test_events_can_be_filtered_by_sport_and_competition(): void
    {
        $this->getJson('/api/v1/events?sport=basketball')->assertJsonCount(1, 'data')->assertJsonPath('data.0.home_team', 'Boston Celtics');

        $competitionId = $this->event('fake-1')->competition_id;
        $this->getJson("/api/v1/events?competition={$competitionId}")->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/events?sport=tennis')->assertJsonCount(0, 'data');
    }

    public function test_fresh_odds_are_bettable(): void
    {
        $this->getJson('/api/v1/events')->assertJsonPath('data.0.markets.0.bettable', true);
    }

    public function test_stale_odds_keep_events_visible_but_not_bettable(): void
    {
        $this->travel(301)->minutes();

        $response = $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([false, false, false], array_map(fn ($e) => $e['markets'][0]['bettable'], $response->json('data')));
        $this->getJson('/api/v1/sports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_suspended_market_started_or_finished_events_are_hidden(): void
    {
        $this->event('fake-1')->markets()->update(['status' => MarketStatus::Suspended, 'suspension_reason' => 'Décision opérateur']);
        $this->event('fake-2')->update(['status' => EventStatus::Cancelled]);
        $this->event('fake-3')->update(['starts_at' => now()->subMinute()]);

        $this->getJson('/api/v1/events')->assertJsonCount(0, 'data');
    }

    public function test_event_detail_returns_its_bettable_markets(): void
    {
        $id = $this->event('fake-2')->id;

        $this->getJson("/api/v1/events/{$id}/markets")
            ->assertOk()
            ->assertJsonPath('data.home_team', 'Liverpool')
            ->assertJsonPath('data.markets.0.name', 'Résultat du match (1X2)')
            ->assertJsonCount(3, 'data.markets.0.selections');
    }

    public function test_event_detail_returns_1x2_then_totals_with_its_line(): void
    {
        $id = $this->event('fake-1')->id;

        $markets = $this->getJson("/api/v1/events/{$id}/markets")->assertOk()->json('data.markets');

        $this->assertSame(['h2h', 'totals'], array_column($markets, 'key'));
        $this->assertSame(['Résultat du match (1X2)', 'Nombre de buts'], array_column($markets, 'name'));
        $this->assertSame([null, '2.5'], array_column($markets, 'line'));
        $this->assertTrue($markets[1]['bettable']);
        $this->assertSame(1, $markets[1]['odds_version']);
        $this->assertSame(
            [['over', '+2.5', 'Plus de 2.5', '1.80'], ['under', '-2.5', 'Moins de 2.5', '2.00']],
            array_map(fn ($s) => [$s['key'], $s['label'], $s['name'], $s['odds']], $markets[1]['selections']),
        );
    }

    public function test_totals_outside_football_is_a_points_total(): void
    {
        $event = $this->event('fake-3');
        $market = $event->markets()->create(['key' => 'totals', 'line' => '215.5', 'odds_updated_at' => now()]);
        $market->selections()->create(['key' => 'under', 'name' => 'Moins de 215.5', 'odds' => '1.90']);
        $market->selections()->create(['key' => 'over', 'name' => 'Plus de 215.5', 'odds' => '1.90']);

        $this->getJson("/api/v1/events/{$event->id}/markets")
            ->assertOk()
            ->assertJsonPath('data.markets.1.name', 'Total de points')
            ->assertJsonPath('data.markets.1.selections.0.label', '+215.5')
            ->assertJsonPath('data.markets.1.selections.1.label', '-215.5');
    }

    public function test_an_event_with_only_totals_displayable_is_not_listed(): void
    {
        $event = $this->event('fake-1');
        $event->markets()->where('key', 'h2h')->update(['status' => MarketStatus::Suspended, 'suspension_reason' => 'Décision opérateur']);

        $this->getJson('/api/v1/events?sport=soccer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.home_team', 'Liverpool');
        $this->getJson('/api/v1/sports')->assertJsonPath('data.0.events_count', 1);
        $this->getJson("/api/v1/events/{$event->id}/markets")->assertOk()->assertJsonPath('data.markets.0.key', 'totals');
    }

    public function test_hidden_or_unknown_event_returns_404(): void
    {
        $event = $this->event('fake-2');
        $event->markets()->update(['status' => MarketStatus::Suspended]);

        $this->getJson("/api/v1/events/{$event->id}/markets")->assertNotFound();
        $this->getJson('/api/v1/events/999999/markets')->assertNotFound();
        $this->getJson('/api/v1/events/abc/markets')->assertNotFound();
    }

    public function test_competitions_list_only_open_ones(): void
    {
        $this->getJson('/api/v1/competitions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/competitions?sport=soccer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Premier League');
    }

    public function test_catalogue_is_public(): void
    {
        $this->getJson('/api/v1/events')->assertOk();
        $this->getJson('/api/v1/sports')->assertOk();
    }
}
