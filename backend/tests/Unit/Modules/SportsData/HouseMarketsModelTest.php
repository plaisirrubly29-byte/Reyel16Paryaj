<?php

namespace Tests\Unit\Modules\SportsData;

use App\Modules\SportsData\Services\HouseMarkets;
use Tests\TestCase;

/** Cotes des marchés maison (V2-D10) : modèle de Poisson tiré du 1X2 et du plus/moins. */
class HouseMarketsModelTest extends TestCase
{
    private const RESULT = ['home' => 0.45, 'draw' => 0.28, 'away' => 0.27];

    public function test_expected_goals_reproduce_the_bookmakers_result_and_total(): void
    {
        [$home, $away] = HouseMarkets::expectedGoals(self::RESULT, 2.5, 0.5);

        $this->assertGreaterThan($away, $home);
        // P(plus de 2,5) d'un total de Poisson h + a : 1 − P(0, 1 ou 2 buts) ≈ 0,5.
        $mu = $home + $away;
        $under = exp(-$mu) * (1 + $mu + $mu * $mu / 2);
        $this->assertEqualsWithDelta(0.5, 1 - $under, 0.001);
    }

    public function test_each_market_carries_the_house_margin(): void
    {
        $markets = HouseMarkets::build(self::RESULT, 2.5, 0.5, 'Arsenal', 'Chelsea', 0.12);

        $this->assertSame(HouseMarkets::KEYS, array_keys($markets));
        foreach ($markets as $key => $selections) {
            $implied = array_sum(array_map(fn ($s) => 1 / (float) $s['odds'], $selections));
            // Marge de 12 % (un peu plus avec l'arrondi vers le bas, un peu moins si une issue improbable est retirée).
            $this->assertEqualsWithDelta(1.12, $implied, 0.05, $key);
        }
        $this->assertSame('Arsenal par 1 but', $markets[HouseMarkets::WINNING_MARGIN]['home_by_1']['name']);
    }

    public function test_a_house_market_never_pays_more_than_the_same_outcome_of_the_result_market(): void
    {
        $markets = HouseMarkets::build(self::RESULT, 2.5, 0.5, 'A', 'B', 0.12);

        // « Match nul » de l'écart de victoire = nul du 1X2 : cote juste 1 / 0,28 = 3,57, avec 12 % → 3,18.
        $this->assertSame('3.180', $markets[HouseMarkets::WINNING_MARGIN]['draw']['odds']);
        // Les écarts de victoire de l'équipe à domicile couvrent exactement sa victoire au 1X2 (45 %).
        $home = array_sum(array_map(fn ($k) => 1 / (float) $markets[HouseMarkets::WINNING_MARGIN][$k]['odds'], ['home_by_1', 'home_by_2', 'home_by_3plus']));
        $this->assertEqualsWithDelta(0.45 * 1.12, $home, 0.01);
    }

    public function test_odds_stay_between_1_01_and_the_maximum(): void
    {
        config(['sports_data.house_markets.max_odds' => 50]);
        // Grand favori, peu de buts : beaucoup d'issues improbables.
        $markets = HouseMarkets::build(['home' => 0.85, 'draw' => 0.1, 'away' => 0.05], 1.5, 0.3, 'A', 'B', 0.12);

        foreach ($markets as $selections) {
            foreach ($selections as $s) {
                $this->assertGreaterThanOrEqual(1.01, (float) $s['odds']);
                $this->assertLessThanOrEqual(50.0, (float) $s['odds']);
            }
        }
    }

    public function test_settlement_rules_on_the_final_score(): void
    {
        $won = fn (string $market, string $selection, int $h, int $a) => HouseMarkets::won($market, $selection, $h, $a);

        $this->assertTrue($won(HouseMarkets::EXACT_GOALS, 'goals_3', 2, 1));
        $this->assertTrue($won(HouseMarkets::EXACT_GOALS, 'goals_5plus', 4, 2));
        $this->assertFalse($won(HouseMarkets::EXACT_GOALS, 'goals_0', 1, 0));
        $this->assertTrue($won(HouseMarkets::GOAL_RANGE, 'range_2_3', 1, 1));
        $this->assertTrue($won(HouseMarkets::GOAL_RANGE, 'range_0_1', 0, 0));
        $this->assertTrue($won(HouseMarkets::WIN_TO_NIL, 'home_clean', 2, 0));
        $this->assertTrue($won(HouseMarkets::WIN_TO_NIL, 'neither', 0, 0));
        $this->assertFalse($won(HouseMarkets::WIN_TO_NIL, 'away_clean', 1, 1));
        $this->assertTrue($won(HouseMarkets::WINNING_MARGIN, 'away_by_3plus', 0, 4));
        $this->assertTrue($won(HouseMarkets::WINNING_MARGIN, 'draw', 2, 2));
        $this->assertFalse($won(HouseMarkets::WINNING_MARGIN, 'home_by_2', 3, 0));
        $this->assertTrue($won(HouseMarkets::RESULT_TOTAL, 'home_and_over', 2, 1));
        $this->assertTrue($won(HouseMarkets::RESULT_TOTAL, 'draw_and_under', 1, 1));
        $this->assertFalse($won(HouseMarkets::RESULT_TOTAL, 'away_and_under', 0, 3));
        $this->assertNull($won(HouseMarkets::GOAL_RANGE, 'range_9_9', 0, 0));
    }
}
