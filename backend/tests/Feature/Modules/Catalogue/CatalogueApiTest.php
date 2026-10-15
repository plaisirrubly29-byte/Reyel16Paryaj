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
        $this->assertSame('h2h', $first['markets'][0]['key']);
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

    public function test_stale_odds_hide_every_event(): void
    {
        $this->travel(301)->minutes();

        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sports')->assertOk()->assertJsonCount(0, 'data');
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
