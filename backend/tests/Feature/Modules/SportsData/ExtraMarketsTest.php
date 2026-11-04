<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\ProviderApiCall;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtraMarketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sports_data.max_odds_age_minutes' => 300,
            'sports_data.theodds.regions' => 'eu',
            'sports_data.extra_markets' => 'double_chance,btts,correct_score',
            'sports_data.extra_markets_hours' => 72,
            'sports_data.extra_markets_competitions' => [],
            'sports_data.extra_markets_daily_credits' => 1000,
        ]);
        $this->travelTo('2026-10-20 10:00:00'); // fake-1 le 21 à 15 h (dans 29 h), fake-2 le 22 à 17 h 30 (dans 55 h 30)
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');     // fake-1 (demain), fake-2 (après-demain)
        app(CatalogueSyncService::class)->sync('basketball_nba'); // fake-3 : pas de football, jamais interrogé
    }

    private function event(string $externalId): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> marchés de fake-1 dans le catalogue public, par clé */
    private function catalogueMarkets(): array
    {
        return collect($this->getJson("/api/v1/events/{$this->event('fake-1')->id}/markets")->assertOk()->json('data.markets'))
            ->keyBy('key')->all();
    }

    public function test_dry_run_announces_the_cost_without_loading_anything(): void
    {
        $this->artisan('sports-data:extra-markets', ['--dry-run' => true])
            ->expectsOutputToContain('2 match(s), au plus 3 crédit(s) par match, coût estimé : 6 crédit(s)')
            ->assertSuccessful();
        $this->artisan('sports-data:extra-markets', ['--dry-run' => true, '--event' => ['fake-2']])
            ->expectsOutputToContain('1 match(s), au plus 3 crédit(s) par match, coût estimé : 3 crédit(s)')
            ->assertSuccessful();
        $this->artisan('sports-data:extra-markets', ['--dry-run' => true, '--hours' => 30, '--markets' => 'spreads,odd_even'])
            ->expectsOutputToContain('1 match(s), au plus 2 crédit(s) par match, coût estimé : 2 crédit(s)')
            ->assertSuccessful();

        $this->assertSame(0, Market::where('key', 'correct_score')->count());
    }

    public function test_correct_score_is_loaded_match_by_match_and_shown_in_the_catalogue(): void
    {
        $this->artisan('sports-data:extra-markets', ['--hours' => 30])->assertSuccessful(); // fake-1 seulement

        $this->assertSame(1, Market::where('key', 'correct_score')->count());
        $this->assertSame(0, $this->event('fake-2')->markets()->where('key', 'correct_score')->count());

        $market = $this->catalogueMarkets()['correct_score'];
        $this->assertSame('Score exact', $market['name']);
        $this->assertSame(
            [['1-0', '1 - 0', '7.50'], ['1-1', '1 - 1', '6.50'], ['2-1', '2 - 1', '9.00'], ['0-1', '0 - 1', '11.00']],
            array_map(fn ($s) => [$s['label'], $s['name'], $s['odds']], $market['selections']),
        );
        $this->assertTrue($market['bettable']);

        // La synchro par compétition suivante ne touche pas aux marchés qu'elle ne fournit pas.
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->assertSame(4, $this->event('fake-1')->markets()->where('key', 'correct_score')->sole()->selections()->count());
    }

    public function test_every_final_score_market_is_loaded_with_its_lines(): void
    {
        config(['sports_data.extra_markets' => 'draw_no_bet,spreads,alternate_spreads,alternate_totals,team_totals,odd_even']);

        $this->artisan('sports-data:extra-markets', ['--hours' => 30])->assertSuccessful();

        $markets = $this->catalogueMarkets();
        $rows = fn (string $key) => array_map(fn ($s) => [$s['key'], $s['label'], $s['name'], $s['odds']], $markets[$key]['selections']);

        $this->assertSame('Remboursé si match nul', $markets['draw_no_bet']['name']);
        $this->assertSame([['home', '1', 'Arsenal', '1.55'], ['away', '2', 'Chelsea', '2.45']], $rows('draw_no_bet'));

        $this->assertSame('Handicap', $markets['spreads']['name']);
        $this->assertSame('-0.5', $markets['spreads']['line']);
        $this->assertSame([['home', '1 (-0.5)', 'Arsenal -0.5', '1.95'], ['away', '2 (+0.5)', 'Chelsea +0.5', '1.90']], $rows('spreads'));

        // Ligne en quart de but (-0.25) écartée ; tri par équipe puis par ligne.
        $this->assertSame([
            ['home_-1.5', '1 (-1.5)', 'Arsenal -1.5', '2.90'], ['home_+1', '1 (+1)', 'Arsenal +1', '1.30'],
            ['away_-1', '2 (-1)', 'Chelsea -1', '3.30'], ['away_+1.5', '2 (+1.5)', 'Chelsea +1.5', '1.40'],
        ], $rows('alternate_spreads'));

        $this->assertSame('Nombre de buts (autres lignes)', $markets['alternate_totals']['name']);
        $this->assertSame([
            ['over_1.5', '+1.5', 'Plus de 1.5', '1.25'], ['under_1.5', '-1.5', 'Moins de 1.5', '3.80'],
            ['over_3.5', '+3.5', 'Plus de 3.5', '2.60'], ['under_3.5', '-3.5', 'Moins de 3.5', '1.48'],
        ], $rows('alternate_totals'));

        $this->assertSame('Buts par équipe', $markets['team_totals']['name']);
        $this->assertSame([
            ['home_over_1.5', '1 +1.5', 'Arsenal : plus de 1.5', '1.60'], ['home_under_1.5', '1 -1.5', 'Arsenal : moins de 1.5', '2.25'],
            ['away_over_0.5', '2 +0.5', 'Chelsea : plus de 0.5', '1.85'], ['away_under_0.5', '2 -0.5', 'Chelsea : moins de 0.5', '1.95'],
        ], $rows('team_totals'));

        $this->assertSame('Nombre de buts pair ou impair', $markets['odd_even']['name']);
        $this->assertSame([['odd', 'Impair', 'Impair', '1.95'], ['even', 'Pair', 'Pair', '1.85']], $rows('odd_even'));
    }

    public function test_loading_stops_at_the_daily_credit_cap(): void
    {
        ProviderApiCall::create([
            'provider' => 'fake', 'endpoint' => '/sports/{sport}/events/{event}/odds', 'competition' => 'soccer_epl',
            'cost' => 998, 'status' => 200, 'called_at' => now()->subHour(),
        ]);

        $this->artisan('sports-data:extra-markets')
            ->expectsOutputToContain('Plafond du jour atteint (998 / 1000 crédits)')
            ->assertSuccessful();

        $this->assertSame(0, Market::where('key', 'correct_score')->count());

        // Les crédits de la veille ne comptent pas.
        $this->travelTo(now()->addDay()->startOfDay()->addHour());
        $this->artisan('sports-data:extra-markets', ['--event' => ['fake-2']])->assertSuccessful();
        $this->assertSame(1, Market::where('key', 'correct_score')->count());
    }

    public function test_only_the_configured_competitions_are_loaded(): void
    {
        config(['sports_data.extra_markets_competitions' => ['soccer_spain_la_liga']]);

        $this->artisan('sports-data:extra-markets', ['--dry-run' => true])
            ->expectsOutputToContain('0 match(s)')
            ->assertSuccessful();
    }

    public function test_extra_markets_are_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sports-data:extra-markets'));

        $this->assertNotNull($event);
        $this->assertSame(config('sports_data.extra_markets_cron'), $event->expression);
    }
}
