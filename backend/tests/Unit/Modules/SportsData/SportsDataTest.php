<?php

namespace Tests\Unit\Modules\SportsData;

use App\Modules\SportsData\Data\SelectionData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SportsDataTest extends TestCase
{
    public function test_provider_numbers_become_exact_decimal_odds(): void
    {
        $this->assertSame('2.05', SelectionData::oddsFromProvider(2.05));
        $this->assertSame('3.6', SelectionData::oddsFromProvider(3.6));
        $this->assertSame('2.15', SelectionData::oddsFromProvider(2.1499999999));
        $this->assertSame('11', SelectionData::oddsFromProvider(11));
        $this->assertSame('1.005', SelectionData::oddsFromProvider('1.005'));
    }

    public function test_odds_must_be_greater_than_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SelectionData('Équipe', '1');
    }

    public function test_odds_must_be_a_decimal_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SelectionData('Équipe', '2,10');
    }

    public function test_fake_provider_returns_upcoming_events_with_odds(): void
    {
        $fake = new FakeSportsDataProvider;

        $events = $fake->events('soccer_epl');

        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->startsAt->isFuture());
        $this->assertSame(['2.10', '3.40', '3.25'], array_map(fn ($s) => $s->odds, $events[0]->markets[0]->selections));
        $this->assertSame(['h2h', 'double_chance', 'totals', 'btts'], array_map(fn ($m) => $m->key, $events[0]->markets));
        $this->assertSame([['Over', '1.80', '2.5'], ['Under', '2.00', '2.5']], array_map(fn ($s) => [$s->name, $s->odds, $s->point], $events[0]->markets[2]->selections));
        $this->assertSame(['h2h'], array_map(fn ($m) => $m->key, $fake->events('basketball_nba')[0]->markets));
        $this->assertSame([], $fake->events('cricket_inconnu'));
        $this->assertNull($fake->quota());
    }

    public function test_fake_provider_can_simulate_odds_changes_stale_odds_and_outages(): void
    {
        $fake = (new FakeSportsDataProvider)
            ->setOdds('fake-1', 'h2h', ['Arsenal' => '1.85', 'Chelsea' => '4.00', 'Draw' => '3.50'])
            ->setOddsUpdatedAt(CarbonImmutable::parse('2026-01-01T00:00:00Z'));

        $market = $fake->events('soccer_epl')[0]->markets[0];
        $this->assertSame('1.85', $market->selections[0]->odds);
        $this->assertSame('2026-01-01T00:00:00+00:00', $market->updatedAt->toIso8601String());

        $fake->failWith(SportsDataException::UNAVAILABLE);
        $this->expectException(SportsDataException::class);
        $fake->events('soccer_epl');
    }

    public function test_fake_provider_returns_a_finished_result(): void
    {
        $result = (new FakeSportsDataProvider)->results('soccer_epl')[0];

        $this->assertTrue($result->completed);
        $this->assertSame(['2', '1'], [$result->homeScore, $result->awayScore]);
    }
}
