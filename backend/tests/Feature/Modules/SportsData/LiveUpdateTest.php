<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\Settlement\Models\Result;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\LiveUpdateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveUpdateTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sports_data.max_odds_age_minutes' => 300,
            'sports_data.max_live_odds_age_minutes' => 20,
            'sports_data.live_window_minutes' => 180,
            'sports_data.live_daily_credits' => 30,
            'sports_data.theodds.markets' => 'h2h',
            'sports_data.theodds.regions' => 'eu',
        ]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);

        // Catalogue synchronisé avant le match, puis coup d'envoi de fake-1 (Arsenal – Chelsea) il y a 30 minutes.
        app(CatalogueSyncService::class)->sync('soccer_epl');
        app(CatalogueSyncService::class)->sync('basketball_nba');
        $kickoff = CarbonImmutable::parse($this->event('fake-1')->starts_at);
        $this->travelTo($kickoff->addMinutes(30));
        $this->fake->setStartsAt('fake-1', $kickoff);
    }

    private function event(string $externalId): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    private function live(): array
    {
        return app(LiveUpdateService::class)->run();
    }

    public function test_a_started_match_goes_live_with_its_score_and_fresh_odds(): void
    {
        $this->fake->setResult('fake-1', '1', '0', completed: false)
            ->setOdds('fake-1', 'h2h', ['Arsenal' => '1.40', 'Chelsea' => '7.50', 'Draw' => '4.33']);

        $outcome = $this->live();

        $this->assertSame(['soccer_epl'], $outcome['updated']);
        $event = $this->event('fake-1');
        $this->assertSame(EventStatus::Live, $event->status);
        $this->assertSame(['1', '0'], [$event->home_score, $event->away_score]);
        $this->assertSame('1.400', $event->markets()->where('key', 'h2h')->first()->selections()->where('key', 'home')->value('odds'));
        $this->assertSame(EventStatus::Scheduled, $this->event('fake-2')->status); // pas commencé
    }

    public function test_the_catalogue_lists_live_matches_with_their_score(): void
    {
        $this->fake->setResult('fake-1', '2', '1', completed: false);
        $this->live();

        $events = collect($this->getJson('/api/v1/events')->assertOk()->json('data'));
        $live = $events->firstWhere('home_team', 'Arsenal');

        $this->assertSame('live', $live['status']);
        $this->assertSame(['home' => '2', 'away' => '1'], $live['score']);
        $this->assertFalse($live['markets'][0]['bettable']); // paris en direct désactivés par défaut (P8-02, E1)
        $this->assertNull($events->firstWhere('home_team', 'Liverpool')['score']);
        $this->getJson("/api/v1/events/{$live['id']}/markets")->assertOk()->assertJsonPath('data.status', 'live');
    }

    public function test_live_odds_stop_being_bettable_sooner_than_pre_match_odds(): void
    {
        config(['betting.allow_live' => true]);
        $this->fake->setResult('fake-1', '0', '0', completed: false);
        $this->live();
        app(CatalogueSyncService::class)->sync('soccer_epl'); // cotes d'avant-match fraîches pour fake-2

        $live = fn () => Market::bettable()->whereHas('event', fn ($e) => $e->where('external_id', 'fake-1'))->count();
        $this->assertTrue(collect($this->getJson('/api/v1/events')->json('data'))->firstWhere('home_team', 'Arsenal')['markets'][0]['bettable']);
        $this->assertGreaterThan(0, $live());
        config(['betting.allow_live' => false]);
        $this->assertSame(0, $live());
        config(['betting.allow_live' => true]);

        $this->travel(21)->minutes();

        $events = collect($this->getJson('/api/v1/events')->json('data'));
        $this->assertFalse($events->firstWhere('home_team', 'Arsenal')['markets'][0]['bettable']);
        $this->assertTrue($events->firstWhere('home_team', 'Liverpool')['markets'][0]['bettable']);
    }

    public function test_a_finished_match_leaves_the_live_tab_and_gets_its_result(): void
    {
        $this->fake->setResult('fake-1', '1', '0', completed: false);
        $this->live();

        $this->travel(90)->minutes();
        $this->fake->setResult('fake-1', '2', '0');
        $this->live();

        $event = $this->event('fake-1');
        $this->assertSame(EventStatus::Finished, $event->status);
        $this->assertSame('2', Result::currentFor($event)->home_score);
        $this->assertNull(collect($this->getJson('/api/v1/events')->json('data'))->firstWhere('home_team', 'Arsenal'));
    }

    public function test_no_provider_call_when_nothing_is_being_played(): void
    {
        $this->travelBack();
        $this->fake->failWith('unavailable'); // tout appel échouerait

        $this->assertSame(['updated' => [], 'skipped' => [], 'errors' => []], $this->live());
    }

    public function test_inactive_competitions_are_not_followed(): void
    {
        Competition::where('external_key', 'soccer_epl')->update(['is_active' => false]);

        $this->assertSame([], app(LiveUpdateService::class)->liveCompetitions());
    }

    public function test_daily_credit_cap_stops_the_updates(): void
    {
        config(['sports_data.live_daily_credits' => 5]); // 2 crédits par passage (scores + 1X2)
        $this->fake->setResult('fake-1', '0', '0', completed: false);

        $this->live();
        $this->live();
        $third = $this->live();

        $this->assertSame(['soccer_epl'], $third['skipped']);
        $this->assertSame(4, app(LiveUpdateService::class)->creditsSpentToday());

        $this->travel(1)->days();
        $this->travelTo(now()->startOfDay()->addMinutes(1));
        $this->assertSame(0, app(LiveUpdateService::class)->creditsSpentToday());
    }

    public function test_command_reports_when_nothing_is_live(): void
    {
        $this->travelBack();

        $this->artisan('sports-data:live')->expectsOutput('Aucun match en cours.')->assertSuccessful();
    }

    public function test_live_updates_are_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sports-data:live'));

        $this->assertNotNull($event);
        $this->assertSame(config('sports_data.live_cron'), $event->expression);
    }
}
