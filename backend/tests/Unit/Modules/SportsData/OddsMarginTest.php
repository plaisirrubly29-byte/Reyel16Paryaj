<?php

namespace Tests\Unit\Modules\SportsData;

use App\Modules\SportsData\Services\OddsMargin;
use Tests\TestCase;

/** Marge de l'opérateur (V2-D03, décision D-18, option C). */
class OddsMarginTest extends TestCase
{
    /** @param array<string, string> $odds */
    private function selections(array $odds): array
    {
        return array_map(fn (string $o) => ['name' => 'x', 'odds' => $o], $odds);
    }

    public function test_example_of_d18_fair_odds_plus_six_percent_rounded_down(): void
    {
        // Cotes justes 1/0,45, 1/0,28, 1/0,27 (sans marge) chez deux bookmakers : D-18 attend 2,09 / 3,36 / 3,49.
        $fair = ['home' => (string) (1 / 0.45), 'draw' => (string) (1 / 0.28), 'away' => (string) (1 / 0.27)];
        $result = OddsMargin::apply($this->selections($fair), [$fair, $fair], 0.06);

        $this->assertSame(['home' => '2.090', 'draw' => '3.360', 'away' => '3.490'], array_map(fn ($s) => $s['odds'], $result));
    }

    public function test_bookmaker_margins_are_removed_before_averaging(): void
    {
        // Deux bookmakers avec chacun leur propre marge (~5 %) ; la cote finale reste sous chaque cote d'origine.
        $a = ['home' => '2.000', 'away' => '1.900'];
        $b = ['home' => '2.050', 'away' => '1.850'];
        $result = OddsMargin::apply($this->selections($a), [$a, $b], 0.05);

        $home = (float) $result['home']['odds'];
        $away = (float) $result['away']['odds'];
        $this->assertLessThanOrEqual(2.05, $home);
        $this->assertLessThanOrEqual(1.9, $away);
        // Marge réelle d'environ 5 % : la somme des probabilités implicites vaut ≈ 1,05.
        $this->assertEqualsWithDelta(1.05, 1 / $home + 1 / $away, 0.01);
    }

    public function test_double_chance_keeps_two_winning_selections(): void
    {
        // Double chance juste (1X2 à 45 / 28 / 27 %) : la somme des probabilités vaut 2, car deux sélections gagnent.
        // Avec 5 % de marge, elle passe à ≈ 2,10 (et non 1,05 : les probabilités ne sont pas ramenées à 1).
        $odds = ['home_draw' => (string) (1 / 0.73), 'home_away' => (string) (1 / 0.72), 'draw_away' => (string) (1 / 0.55)];
        $result = OddsMargin::apply($this->selections($odds), [$odds, $odds], 0.05);

        $sum = array_sum(array_map(fn ($s) => 1 / (float) $s['odds'], $result));
        $this->assertEqualsWithDelta(2.1, $sum, 0.02);
        $this->assertSame('1.300', $result['home_draw']['odds']); // 1 / (0,73 × 1,05) = 1,3046 → 1,30
    }

    public function test_never_above_the_best_bookmaker_odds_and_never_below_1_01(): void
    {
        $a = ['yes' => '1.010', 'no' => '30.000'];
        $b = ['yes' => '1.010', 'no' => '25.000'];
        $result = OddsMargin::apply($this->selections($a), [$a, $b], 0.0001);

        $this->assertSame('1.010', $result['yes']['odds']);
        $this->assertLessThanOrEqual(30.0, (float) $result['no']['odds']);
    }

    public function test_one_bookmaker_only_keeps_the_reference_odds(): void
    {
        $a = ['home' => '2.000', 'away' => '1.900'];
        $this->assertSame($this->selections($a), OddsMargin::apply($this->selections($a), [$a], 0.06));

        // Un bookmaker aux sélections différentes (autre ligne, autre découpage) ne compte pas.
        $other = ['home' => '2.100', 'draw' => '3.000', 'away' => '3.500'];
        $this->assertSame($this->selections($a), OddsMargin::apply($this->selections($a), [$a, $other], 0.06));
    }

    public function test_rates_by_sport_market_and_live(): void
    {
        $this->assertSame(0.06, OddsMargin::rate('soccer_epl', 'h2h', false));
        $this->assertSame(0.05, OddsMargin::rate('basketball_nba', 'h2h', false));
        $this->assertSame(0.05, OddsMargin::rate('soccer_epl', 'totals', false));
        $this->assertSame(0.08, OddsMargin::rate('soccer_epl', 'correct_score', false));
        $this->assertEqualsWithDelta(0.08, OddsMargin::rate('soccer_epl', 'h2h', true), 1e-9);
    }

    public function test_applied_with_real_money_only(): void
    {
        config(['wallet.simulated' => true]);
        $this->assertFalse(OddsMargin::enabled());

        config(['wallet.simulated' => false]);
        $this->assertTrue(OddsMargin::enabled());

        config(['sports_data.margin.enabled' => false]);
        $this->assertFalse(OddsMargin::enabled());
    }
}
