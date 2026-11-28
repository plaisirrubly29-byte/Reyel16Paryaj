<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\Settlement\Models\Result;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\ProviderApiCall;
use App\Modules\SportsData\Providers\SportMonksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SportMonksProviderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'cle-sportmonks-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sports_data.sportmonks.url' => 'https://api.sportmonks.com/v3',
            'sports_data.sportmonks.key' => self::KEY,
            'sports_data.sportmonks.leagues' => ['*'],
            'sports_data.sportmonks.bookmakers' => [2, 9],
            'sports_data.sportmonks.reference_bookmaker' => 2,
            'sports_data.sportmonks.league_keys' => [8 => 'soccer_epl'],
            'sports_data.sportmonks.horizon_days' => 7,
            'sports_data.sportmonks.inplay_cache_seconds' => 20,
            'sports_data.max_events_per_competition' => 10,
            'sports_data.horizon_days' => 7,
            'sports_data.max_odds_age_minutes' => 100000,
        ]);
        Cache::flush();
        $this->app->forgetInstance(SportMonksProvider::class);
        Http::preventStrayRequests();
    }

    private function provider(): SportMonksProvider
    {
        return app(SportMonksProvider::class);
    }

    /** @return array<string, mixed> */
    private function odd(int $market, int $bookmaker, string $label, string $value, array $extra = []): array
    {
        return ['market_id' => $market, 'bookmaker_id' => $bookmaker, 'label' => $label, 'value' => $value,
            'stopped' => false, 'latest_bookmaker_update' => now()->subMinutes(5)->format('Y-m-d H:i:s')] + $extra;
    }

    /** Match au format documenté de SportMonks v3 (participants, league.country, odds). */
    private function fixture(int $id = 19722086, int $leagueId = 8, ?int $startsAt = null): array
    {
        return [
            'id' => $id,
            'league_id' => $leagueId,
            'name' => 'Manchester United vs Brentford',
            'starting_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'starting_at_timestamp' => $startsAt ?? now()->addDay()->startOfMinute()->getTimestamp(),
            'participants' => [
                ['id' => 1, 'name' => 'Brentford', 'meta' => ['location' => 'away']],
                ['id' => 2, 'name' => 'Manchester United', 'meta' => ['location' => 'home']],
            ],
            'league' => ['id' => $leagueId, 'name' => 'Premier League', 'country' => ['name' => 'England']],
            'odds' => [
                // Bookmaker 9 en premier dans la réponse : la référence (2) doit quand même passer devant.
                $this->odd(1, 9, 'Home', '1.95'), $this->odd(1, 9, 'Draw', '3.60'), $this->odd(1, 9, 'Away', '4.00'),
                $this->odd(1, 2, 'Home', '1.90'), $this->odd(1, 2, 'Draw', '3.50'), $this->odd(1, 2, 'Away', '4.20'),
                $this->odd(80, 2, 'Over', '1.40', ['total' => '1.5']), $this->odd(80, 2, 'Under', '2.80', ['total' => '1.5']),
                $this->odd(80, 2, 'Over', '1.85', ['total' => '2.5']), $this->odd(80, 2, 'Under', '1.95', ['total' => '2.5']),
                $this->odd(14, 2, 'Yes', '1.70'), $this->odd(14, 2, 'No', '2.05'),
                $this->odd(2, 2, 'Man Utd or Draw', '1.25'), $this->odd(2, 2, 'Draw or Brentford', '1.95'),
                $this->odd(2, 2, 'Man Utd or Brentford', '1.30'),
                $this->odd(57, 2, '1', '8.00', ['name' => '1-0']), $this->odd(57, 2, '2', '15.00', ['name' => '2-0']),
                $this->odd(57, 2, 'Draw', '9.00', ['name' => '1-1']),
                // Bookmaker non suivi : ignoré.
                $this->odd(1, 77, 'Home', '9.00'), $this->odd(1, 77, 'Draw', '9.00'), $this->odd(1, 77, 'Away', '9.00'),
            ],
        ];
    }

    /** Réponses du faux SportMonks : modifiables en cours de test (Http::fake garde le premier faux enregistré). */
    private array $fixtures = [];

    private array $results = [];

    private array $inplay = [];

    private bool $faked = false;

    private function fakeApi(array $fixtures, array $results = [], array $inplay = []): void
    {
        [$this->fixtures, $this->results, $this->inplay] = [$fixtures, $results, $inplay];
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake([
            'api.sportmonks.com/v3/my/leagues*' => Http::response(['data' => [
                ['id' => 8, 'sport_id' => 1, 'name' => 'Premier League', 'active' => true, 'country' => ['name' => 'England']],
                ['id' => 513, 'sport_id' => 1, 'name' => 'Premiership Play-Offs', 'active' => true, 'country' => ['name' => 'Scotland']],
                ['id' => 1, 'sport_id' => 2, 'name' => 'Twenty20 International', 'active' => true],
            ], 'pagination' => ['has_more' => false], 'rate_limit' => ['remaining' => 170]]),
            'api.sportmonks.com/v3/football/livescores/inplay*' => fn () => Http::response(['data' => $this->inplay]),
            'api.sportmonks.com/v3/football/fixtures/between/*' => function (Request $request) {
                $data = str_contains($request->url(), 'odds') ? $this->fixtures : $this->results;
                if (str_contains(urldecode($request->url()), 'fixtureLeagues:513')) {
                    $data = [];
                }

                return Http::response($data === [] ? ['message' => 'No result(s) found matching your request.'] : ['data' => $data, 'pagination' => ['has_more' => false]]);
            },
        ]);
    }

    public function test_league_keys_reuse_the_odds_api_keys_and_fall_back_to_the_league_id(): void
    {
        $this->fakeApi([]);

        $sports = $this->provider()->sports();

        $this->assertSame(['soccer_epl', 'soccer_sm_513'], array_map(fn ($s) => $s->key, $sports));
        $this->assertSame('Premiership Play-Offs', $sports[1]->title);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/my/leagues') && ! str_contains($r->url(), 'include'));
        $this->assertSame(513, $this->provider()->leagueForKey('soccer_sm_513'));
        $this->assertSame(8, $this->provider()->leagueForKey('soccer_epl'));

        $this->expectException(SportsDataException::class);
        $this->provider()->leagueForKey('basketball_nba');
    }

    public function test_events_are_mapped_to_the_catalogue_market_format(): void
    {
        $this->fakeApi([$this->fixture()]);

        [$event] = $this->provider()->events('soccer_epl');

        $this->assertSame('19722086', $event->externalId);
        $this->assertSame('Manchester United', $event->homeTeam);
        $this->assertSame('Brentford', $event->awayTeam);
        $this->assertSame('Premier League - England', $event->competition);

        // Référence (bet365 = 2) d'abord, bookmaker non suivi écarté.
        $this->assertSame('sportmonks:2', $event->markets[0]->bookmaker);
        $this->assertNotContains('sportmonks:77', array_map(fn ($m) => $m->bookmaker, $event->markets));

        $markets = collect($event->markets)->where('bookmaker', 'sportmonks:2')->keyBy('key');
        $names = fn (string $key) => array_map(fn ($s) => $s->name.($s->point !== null ? " {$s->point}" : '').'='.$s->odds, $markets[$key]->selections);

        $this->assertSame(['Manchester United=1.9', 'Draw=3.5', 'Brentford=4.2'], $names('h2h'));
        $this->assertSame(['Over 2.5=1.85', 'Under 2.5=1.95'], $names('totals'));
        $this->assertSame(['Yes=1.7', 'No=2.05'], $names('btts'));
        $this->assertSame([
            'Manchester United or Draw=1.25', 'Draw or Brentford=1.95', 'Manchester United or Brentford=1.3',
        ], $names('double_chance'));
        // « 2-0 » avec le libellé 2 : victoire 2-0 de l'équipe à l'extérieur.
        $this->assertSame([
            'Manchester United:1|Brentford:0=8', 'Manchester United:0|Brentford:2=15', 'Manchester United:1|Brentford:1=9',
        ], $names('correct_score'));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', self::KEY)
            && ! str_contains($r->url(), self::KEY)
            && str_contains(urldecode($r->url()), 'fixtureLeagues:8'));
        $call = ProviderApiCall::where('provider', 'sportmonks')->sole();
        $this->assertSame('/football/fixtures/between/{date}/{date}', $call->endpoint);
        $this->assertSame('soccer_epl', $call->competition);
        $this->assertSame(0, $call->cost);
    }

    public function test_the_command_syncs_the_catalogue_with_the_sportmonks_provider(): void
    {
        $this->fakeApi([$this->fixture()]);

        $this->artisan('sports-data:sportmonks')->assertSuccessful();

        $event = Event::where('provider', 'sportmonks')->sole();
        $this->assertSame('19722086', $event->external_id);
        $this->assertSame('Premier League', $event->competition->name);
        $this->assertSame('Football', $event->competition->sport->name);
        $this->assertTrue(Market::where('event_id', $event->id)->where('key', 'h2h')->exists());
        $this->assertTrue(Market::where('event_id', $event->id)->where('key', 'btts')->exists());
        $this->assertTrue(Competition::where('provider', 'sportmonks')->where('external_key', 'soccer_sm_513')->doesntExist(),
            'Une ligue sans match ne crée pas de compétition.');
    }

    public function test_finished_matches_are_imported_with_the_regular_time_score(): void
    {
        $this->fakeApi([$this->fixture(startsAt: now()->addDay()->getTimestamp())]);
        $this->artisan('sports-data:sportmonks')->assertSuccessful();
        $event = Event::where('provider', 'sportmonks')->sole();
        $event->update(['starts_at' => now()->subHours(3)]);

        // Après prolongation : réglé sur le score de fin de 2e mi-temps (1-1), pas sur le score final (2-1).
        $finished = $this->fixture() + ['state' => ['developer_name' => 'AET'], 'scores' => [
            ['description' => '2ND_HALF', 'score' => ['participant' => 'home', 'goals' => 1]],
            ['description' => '2ND_HALF', 'score' => ['participant' => 'away', 'goals' => 1]],
            ['description' => 'CURRENT', 'score' => ['participant' => 'home', 'goals' => 2]],
            ['description' => 'CURRENT', 'score' => ['participant' => 'away', 'goals' => 1]],
        ]];
        Cache::flush();
        $this->fakeApi([], [$finished]);

        $this->artisan('sports-data:sportmonks', ['--results' => true])->assertSuccessful();

        $result = Result::where('event_id', $event->id)->latest('id')->firstOrFail();
        $this->assertSame([1, 1], [(int) $result->home_score, (int) $result->away_score]);
    }

    public function test_live_scores_come_from_one_inplay_call(): void
    {
        $this->fakeApi([$this->fixture()]);
        $this->artisan('sports-data:sportmonks')->assertSuccessful();
        $event = Event::where('provider', 'sportmonks')->sole();
        $event->update(['starts_at' => now()->subMinutes(30)]);

        Cache::flush();
        $this->fakeApi([], [], [$this->fixture() + ['state' => ['developer_name' => 'INPLAY_1ST_HALF'], 'scores' => [
            ['description' => 'CURRENT', 'score' => ['participant' => 'away', 'goals' => 1]],
        ]]]);

        $this->artisan('sports-data:sportmonks', ['--live' => true])->assertSuccessful();

        $event->refresh();
        $this->assertSame(EventStatus::Live, $event->status);
        $this->assertSame([0, 1], [(int) $event->home_score, (int) $event->away_score]);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'livescores/inplay')));
    }

    public function test_the_main_provider_skips_the_leagues_covered_by_sportmonks(): void
    {
        $this->fakeApi([$this->fixture()]);
        $this->artisan('sports-data:sportmonks')->assertSuccessful();

        config(['sports_data.sports' => ['soccer_epl', 'basketball_nba'], 'sports_data.budget_enabled' => false]);

        $this->artisan('sports-data:sync')
            ->expectsOutputToContain('Couvertes par SportMonks : soccer_epl')
            ->assertSuccessful();

        $this->assertTrue(Competition::where('provider', 'fake')->where('external_key', 'soccer_epl')->doesntExist());
        $this->assertTrue(Competition::where('provider', 'fake')->where('external_key', 'basketball_nba')->exists());
    }

    public function test_without_a_key_nothing_is_called(): void
    {
        config(['sports_data.sportmonks.key' => null]);

        $this->artisan('sports-data:sportmonks')
            ->expectsOutputToContain('SPORTMONKS_API_KEY est vide')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_rate_limit_answer_is_reported_as_quota_exhausted(): void
    {
        Http::fake(['api.sportmonks.com/*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        try {
            $this->provider()->events('soccer_epl');
            $this->fail('Exception attendue.');
        } catch (SportsDataException $e) {
            $this->assertSame(SportsDataException::QUOTA_EXHAUSTED, $e->reason);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }
}
