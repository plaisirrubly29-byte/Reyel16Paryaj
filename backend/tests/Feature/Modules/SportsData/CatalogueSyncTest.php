<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CatalogueSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_events_per_competition' => 10, 'sports_data.horizon_days' => 7, 'sports_data.max_odds_age_minutes' => 300]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);
    }

    private function sync(string $key = 'soccer_epl')
    {
        return app(CatalogueSyncService::class)->sync($key);
    }

    private function h2h(string $externalId): Market
    {
        return Event::where('external_id', $externalId)->firstOrFail()->markets()->where('key', 'h2h')->firstOrFail();
    }

    private function totals(string $externalId): Market
    {
        return Event::where('external_id', $externalId)->firstOrFail()->markets()->where('key', 'totals')->firstOrFail();
    }

    public function test_first_sync_creates_sport_competition_teams_events_and_odds(): void
    {
        $report = $this->sync();

        $this->assertTrue($report->succeeded());
        $this->assertSame(2, $report->eventsCreated);
        $this->assertSame('Football', Sport::where('slug', 'soccer')->value('name'));
        $competition = Competition::where('external_key', 'soccer_epl')->firstOrFail();
        $this->assertSame(['Premier League', 'Angleterre'], [$competition->name, $competition->country]);

        $event = Event::with(['homeTeam', 'awayTeam'])->where('external_id', 'fake-1')->firstOrFail();
        $this->assertSame('Arsenal', $event->homeTeam->name);
        $this->assertSame(EventStatus::Scheduled, $event->status);

        $market = $this->h2h('fake-1');
        $this->assertSame(1, $market->odds_version);
        $this->assertSame(MarketStatus::Open, $market->status);
        $this->assertSame('fakebook', $market->source_bookmaker);
        $this->assertSame(
            ['away' => '3.400', 'draw' => '3.250', 'home' => '2.100'],
            $market->selections()->orderBy('key')->pluck('odds', 'key')->all(),
        );
        $this->assertSame('Match nul', $market->selections()->where('key', 'draw')->value('name'));
        $this->assertSame(3, OddsSnapshot::where('odds_version', 1)->whereIn('selection_id', $market->selections()->pluck('id'))->count());
    }

    public function test_replaying_the_same_data_creates_nothing(): void
    {
        $this->sync();
        $snapshots = OddsSnapshot::count();

        $report = $this->sync();

        $this->assertSame(0, $report->eventsCreated);
        $this->assertSame(2, $report->eventsUpdated);
        $this->assertSame(0, $report->oddsChanged);
        $this->assertSame(2, Event::count());
        $this->assertSame($snapshots, OddsSnapshot::count());
        $this->assertSame(1, $this->h2h('fake-1')->odds_version);
    }

    public function test_an_odds_change_creates_a_new_version_with_history(): void
    {
        $this->sync();

        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95', 'Chelsea' => '3.60', 'Draw' => '3.30'])
            ->setOddsUpdatedAt(CarbonImmutable::now());
        $report = $this->sync();

        $market = $this->h2h('fake-1');
        $this->assertSame(1, $report->oddsChanged);
        $this->assertSame(2, $market->odds_version);
        $this->assertSame('1.950', $market->selections()->where('key', 'home')->value('odds'));

        $home = $market->selections()->where('key', 'home')->firstOrFail();
        $this->assertSame(['1' => '2.100', '2' => '1.950'], $home->snapshots()->orderBy('odds_version')->pluck('odds', 'odds_version')->all());
    }

    public function test_older_odds_than_stored_are_ignored(): void
    {
        $this->sync();

        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '9.00', 'Chelsea' => '1.10', 'Draw' => '8.00'])
            ->setOddsUpdatedAt(CarbonImmutable::now()->subDay());
        $this->sync();

        $this->assertSame(1, $this->h2h('fake-1')->odds_version);
        $this->assertSame('2.100', $this->h2h('fake-1')->selections()->where('key', 'home')->value('odds'));
    }

    public function test_provider_failure_changes_nothing_and_is_reported(): void
    {
        $this->sync();
        $this->fake->failWith(SportsDataException::UNAVAILABLE);

        $report = $this->sync();

        $this->assertFalse($report->succeeded());
        $this->assertStringStartsWith('unavailable', $report->error);
        $this->assertSame(2, Event::count());
        $this->assertSame(1, $this->h2h('fake-1')->odds_version);
    }

    public function test_the_number_of_events_is_limited(): void
    {
        config(['sports_data.max_events_per_competition' => 1]);

        $report = $this->sync();

        $this->assertSame(1, Event::count());
        $this->assertSame(1, $report->skipped);
        $this->assertSame('fake-1', Event::value('external_id')); // le plus proche est gardé
    }

    public function test_sports_without_draw_are_supported(): void
    {
        $this->sync('basketball_nba');

        $this->assertSame(['away', 'home'], $this->h2h('fake-3')->selections()->orderBy('key')->pluck('key')->all());
        $this->assertSame('Basketball', Sport::where('slug', 'basketball')->value('name'));
    }

    public function test_a_finished_event_is_no_longer_modified(): void
    {
        $this->sync();
        Event::where('external_id', 'fake-1')->update(['status' => EventStatus::Finished]);

        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.50', 'Chelsea' => '5.00', 'Draw' => '4.00'])
            ->setOddsUpdatedAt(CarbonImmutable::now());
        $this->sync();

        $this->assertSame(1, $this->h2h('fake-1')->odds_version);
    }

    public function test_fresh_odds_lift_an_automatic_suspension_but_not_an_admin_one(): void
    {
        $this->sync();
        $this->h2h('fake-1')->update(['status' => MarketStatus::Suspended, 'suspension_reason' => 'auto:stale']);
        $this->h2h('fake-2')->update(['status' => MarketStatus::Suspended, 'suspension_reason' => 'Décision opérateur']);

        $this->fake->setOddsUpdatedAt(CarbonImmutable::now());
        $this->sync();

        $this->assertSame(MarketStatus::Open, $this->h2h('fake-1')->status);
        $this->assertSame(MarketStatus::Suspended, $this->h2h('fake-2')->status);
    }

    public function test_stale_odds_are_not_bettable(): void
    {
        $this->sync();
        $this->assertSame(4, Market::bettable()->count()); // 2 matchs × (1X2 + plus/moins)

        $this->travel(301)->minutes();

        $this->assertSame(0, Market::bettable()->count());
        $this->assertFalse($this->h2h('fake-1')->isBettable());
    }

    public function test_totals_market_is_synced_with_its_line(): void
    {
        $this->sync();

        $market = $this->totals('fake-1');
        $this->assertSame('2.5', $market->line);
        $this->assertSame(1, $market->odds_version);
        $this->assertSame(
            ['over' => ['Plus de 2.5', '1.800'], 'under' => ['Moins de 2.5', '2.000']],
            $market->selections()->orderBy('key')->get()->mapWithKeys(fn ($s) => [$s->key => [$s->name, $s->odds]])->all(),
        );
        $this->assertSame(['2.5', '2.5'], OddsSnapshot::whereIn('selection_id', $market->selections()->pluck('id'))->pluck('line')->all());
        $this->assertNull($this->h2h('fake-1')->line);

        // Le basket du faux fournisseur n'a pas de plus/moins.
        $this->sync('basketball_nba');
        $this->assertSame(['h2h'], Event::where('external_id', 'fake-3')->firstOrFail()->markets()->pluck('key')->all());
    }

    public function test_a_line_change_is_a_new_odds_version_even_with_equal_prices(): void
    {
        $this->sync();

        $this->fake->setOdds('fake-1', 'totals', ['Over' => '1.80', 'Under' => '2.00'], '3.50')
            ->setOddsUpdatedAt(CarbonImmutable::now());
        $report = $this->sync();

        $market = $this->totals('fake-1');
        $this->assertSame(1, $report->oddsChanged);
        $this->assertSame(2, $market->odds_version);
        $this->assertSame('3.5', $market->line);
        $this->assertSame('Plus de 3.5', $market->selections()->where('key', 'over')->value('name'));
        $this->assertSame(1, $this->h2h('fake-1')->odds_version);

        $over = $market->selections()->where('key', 'over')->firstOrFail();
        $this->assertSame(['1' => '2.5', '2' => '3.5'], $over->snapshots()->orderBy('odds_version')->pluck('line', 'odds_version')->all());

        // Rejouer la même ligne ne crée pas de version.
        $this->sync();
        $this->assertSame(2, $this->totals('fake-1')->odds_version);
    }

    public function test_a_totals_price_change_bumps_only_the_totals_version(): void
    {
        $this->sync();

        $this->fake->setOdds('fake-2', 'totals', ['Over' => '1.70', 'Under' => '2.10'])->setOddsUpdatedAt(CarbonImmutable::now());
        $this->sync();

        $this->assertSame(2, $this->totals('fake-2')->odds_version);
        $this->assertSame('1.700', $this->totals('fake-2')->selections()->where('key', 'over')->value('odds'));
        $this->assertSame(1, $this->h2h('fake-2')->odds_version);
    }

    public function test_an_invalid_totals_market_is_skipped_without_rejecting_the_event(): void
    {
        Log::spy();
        $this->fake->setOdds('fake-1', 'totals', ['Over' => '1.80', 'Under' => '2.00'], '0') // ligne invalide
            ->setOdds('fake-2', 'totals', ['Over' => '1.65', 'Draw' => '2.20']);              // sélection inconnue

        $report = $this->sync();

        $this->assertSame(2, $report->eventsCreated);
        $this->assertSame(0, $report->skipped);
        foreach (['fake-1', 'fake-2'] as $id) {
            $this->assertSame(1, $this->h2h($id)->odds_version);
            $this->assertFalse(Event::where('external_id', $id)->firstOrFail()->markets()->where('key', 'totals')->exists());
            Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'sports_data.market_rejected'
                && $context['id'] === $id && $context['market'] === 'totals')->once();
        }
    }

    public function test_an_invalid_h2h_market_still_rejects_the_event(): void
    {
        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '2.10', 'Inconnu' => '3.40']);

        $report = $this->sync();

        $this->assertSame(1, $report->skipped);
        $this->assertNull(Event::where('external_id', 'fake-1')->first());
        $this->assertSame(0, Market::whereHas('event', fn ($q) => $q->where('external_id', 'fake-1'))->count());
    }

    public function test_sync_command_reports_each_competition(): void
    {
        config(['sports_data.sports' => ['soccer_epl', 'basketball_nba']]);

        $this->artisan('sports-data:sync')
            ->expectsOutputToContain('soccer_epl')
            ->expectsOutputToContain('basketball_nba')
            ->assertSuccessful();

        $this->assertSame(3, Event::count());
    }

    public function test_sync_is_scheduled_with_the_configured_frequency(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sports-data:sync'));

        $this->assertNotNull($event);
        $this->assertSame(config('sports_data.sync_cron'), $event->expression);
    }
}
