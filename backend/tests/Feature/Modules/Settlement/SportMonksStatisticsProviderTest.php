<?php

namespace Tests\Feature\Modules\Settlement;

use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Providers\SportMonksStatisticsProvider;
use App\Modules\SportsData\Providers\SportMonksProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SportMonksStatisticsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sports_data.statistics.provider' => 'sportmonks',
            'sports_data.sportmonks.url' => 'https://api.sportmonks.com/v3',
            'sports_data.sportmonks.key' => 'cle-de-test',
        ]);
        Cache::flush();
        $this->app->forgetInstance(SportMonksProvider::class);
        $this->app->forgetInstance(MatchStatisticsProvider::class);
        Http::preventStrayRequests();
    }

    private function score(string $description, string $side, int $goals): array
    {
        return ['description' => $description, 'score' => ['participant' => $side, 'goals' => $goals]];
    }

    public function test_half_time_score_corners_and_cards_are_read_from_sportmonks(): void
    {
        Http::fake([
            'api.sportmonks.com/v3/football/fixtures/date/2026-11-27*' => Http::response(['data' => [
                [
                    'id' => 19722085, 'starting_at_timestamp' => 1795809600, 'state' => ['developer_name' => 'FT'],
                    'participants' => [
                        ['id' => 18, 'name' => 'Chelsea', 'meta' => ['location' => 'away']],
                        ['id' => 63, 'name' => 'Nottingham Forest', 'meta' => ['location' => 'home']],
                    ],
                    'scores' => [
                        $this->score('1ST_HALF', 'home', 1), $this->score('1ST_HALF', 'away', 0),
                        $this->score('CURRENT', 'home', 3), $this->score('CURRENT', 'away', 2),
                    ],
                ],
                // Après prolongation : pas « terminé » au sens du temps réglementaire.
                ['id' => 2, 'starting_at_timestamp' => 1795809600, 'state' => ['developer_name' => 'AET'],
                    'participants' => [['id' => 1, 'name' => 'A', 'meta' => ['location' => 'home']], ['id' => 3, 'name' => 'B', 'meta' => ['location' => 'away']]],
                    'scores' => []],
            ], 'pagination' => ['has_more' => false]]),
            'api.sportmonks.com/v3/football/fixtures/19722085*' => Http::response(['data' => ['id' => 19722085, 'statistics' => [
                ['type_id' => 34, 'participant_id' => 18, 'location' => 'away', 'data' => ['value' => 2]],
                ['type_id' => 34, 'participant_id' => 63, 'location' => 'home', 'data' => ['value' => 1]],
                ['type_id' => 84, 'participant_id' => 18, 'location' => 'away', 'data' => ['value' => 3]],
                ['type_id' => 84, 'participant_id' => 63, 'location' => 'home', 'data' => ['value' => 2]],
                ['type_id' => 83, 'participant_id' => 63, 'location' => 'home', 'data' => ['value' => 1]],
            ]]]),
        ]);

        $provider = app(MatchStatisticsProvider::class);
        $this->assertInstanceOf(SportMonksStatisticsProvider::class, $provider);

        [$fixture, $extraTime] = $provider->fixtures(CarbonImmutable::parse('2026-11-27', 'UTC'));
        $this->assertSame(['Nottingham Forest', 'Chelsea', 3, 2, 1, 0], [
            $fixture->homeTeam, $fixture->awayTeam, $fixture->homeGoals, $fixture->awayGoals, $fixture->firstHalfHome, $fixture->firstHalfAway,
        ]);
        $this->assertTrue($fixture->finished);
        $this->assertFalse($extraTime->finished);

        $stats = $provider->statistics($fixture);
        $this->assertSame([1, 0, 1, 2, 3, 3], [
            $stats->firstHalfHome, $stats->firstHalfAway, $stats->cornersHome, $stats->cornersAway, $stats->cardsHome, $stats->cardsAway,
        ]);
    }

    public function test_statistics_not_yet_published_stay_unknown(): void
    {
        Http::fake(['api.sportmonks.com/v3/football/fixtures/5*' => Http::response(['data' => ['id' => 5, 'statistics' => []]])]);

        $stats = app(MatchStatisticsProvider::class)->statistics(new FixtureData(
            '5', 'A', 'B', CarbonImmutable::now(), true, 1, 0, 1, 0, '1', '2',
        ));

        $this->assertNull($stats->cornersHome);
        $this->assertNull($stats->cardsAway);
    }
}
