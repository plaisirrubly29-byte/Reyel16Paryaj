<?php

namespace Tests\Unit\Modules\SportsData;

use App\Modules\SportsData\Services\LiveOddsModel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Modèle de cotes en direct (D-20) : force des équipes tirée des cotes, score et temps restant. */
class LiveOddsModelTest extends TestCase
{
    /** 1X2 juste d'un match équilibré à domicile : 45 % / 27 % / 28 %, plus de 2,5 buts à 52 %. */
    private const RESULT = ['home' => 0.45, 'draw' => 0.27, 'away' => 0.28];

    private function anchor(): array
    {
        $rates = LiveOddsModel::fit(self::RESULT, 2.5, 0.52, 0, 0, LiveOddsModel::PLAYING_MINUTES, 2.6);
        $this->assertNotNull($rates);

        return [$rates[0], $rates[1], LiveOddsModel::drawBoost(self::RESULT, $rates[0], $rates[1])];
    }

    public function test_the_kickoff_fit_reproduces_the_bookmakers_fair_odds(): void
    {
        [$home, $away, $boost] = $this->anchor();
        $p = LiveOddsModel::probabilities($home, $away, $boost, 0, 0, LiveOddsModel::PLAYING_MINUTES, 2.5);

        $this->assertGreaterThan($away, $home, 'L\'équipe favorite attaque plus.');
        $this->assertEqualsWithDelta(self::RESULT['home'], $p['home'], 0.01);
        $this->assertEqualsWithDelta(self::RESULT['draw'], $p['draw'], 0.01);
        $this->assertEqualsWithDelta(self::RESULT['away'], $p['away'], 0.01);
        $this->assertEqualsWithDelta(0.52, $p['over'], 0.005);
        $this->assertEqualsWithDelta(1.0, $p['home'] + $p['draw'] + $p['away'], 1e-9);
    }

    public function test_time_passing_at_0_0_makes_the_draw_and_the_under_more_likely(): void
    {
        [$home, $away, $boost] = $this->anchor();
        $start = LiveOddsModel::probabilities($home, $away, $boost, 0, 0, 96, 2.5);
        $late = LiveOddsModel::probabilities($home, $away, $boost, 0, 0, 20, 2.5);

        $this->assertGreaterThan($start['draw'], $late['draw']);
        $this->assertLessThan($start['home'], $late['home']);
        $this->assertGreaterThan($start['under'], $late['under']);
    }

    public function test_a_goal_moves_the_odds_towards_the_scoring_team(): void
    {
        [$home, $away, $boost] = $this->anchor();
        $before = LiveOddsModel::probabilities($home, $away, $boost, 0, 0, 60, 2.5);
        $after = LiveOddsModel::probabilities($home, $away, $boost, 0, 1, 60, 2.5);

        $this->assertGreaterThan($before['away'] + 0.2, $after['away']);
        $this->assertLessThan($before['home'], $after['home']);
        $this->assertGreaterThan($before['over'], $after['over']);
    }

    public function test_at_the_final_whistle_the_score_decides(): void
    {
        [$home, $away, $boost] = $this->anchor();
        $p = LiveOddsModel::probabilities($home, $away, $boost, 2, 1, 0, 2.5);

        $this->assertEqualsWithDelta(1.0, $p['home'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $p['over'], 1e-9);
    }

    public function test_live_odds_recalibrate_the_team_strengths(): void
    {
        // À la 30e minute, 0-0 : le marché donne désormais l'équipe à l'extérieur favorite.
        $live = ['home' => 0.30, 'draw' => 0.35, 'away' => 0.35];
        $rates = LiveOddsModel::fit($live, 2.5, 0.40, 0, 0, 66, 2.6);
        $this->assertNotNull($rates);
        [$home, $away] = $rates;
        $this->assertGreaterThan($home, $away);

        $p = LiveOddsModel::probabilities($home, $away, 1.0, 0, 0, 66, 2.5);
        $this->assertEqualsWithDelta($live['home'] - $live['away'], $p['home'] - $p['away'], 0.01);
        $this->assertEqualsWithDelta(0.40, $p['over'], 0.005);

        // Trop tard pour en tirer quoi que ce soit.
        $this->assertNull(LiveOddsModel::fit($live, 2.5, 0.40, 0, 0, 3, 2.6));
    }

    public function test_the_clock_is_estimated_from_the_kickoff_time(): void
    {
        $kickoff = CarbonImmutable::parse('2026-11-22 15:00:00');

        $this->assertSame(96.0, LiveOddsModel::remainingMinutes($kickoff, $kickoff));
        $this->assertSame(1, LiveOddsModel::minute($kickoff, $kickoff));
        $this->assertSame(30, LiveOddsModel::minute($kickoff, $kickoff->addMinutes(29)->addSeconds(30)));
        $this->assertSame(45, LiveOddsModel::minute($kickoff, $kickoff->addMinutes(46)));
        $this->assertNull(LiveOddsModel::minute($kickoff, $kickoff->addMinutes(55)), 'Pause');
        $this->assertSame(49.0, LiveOddsModel::remainingMinutes($kickoff, $kickoff->addMinutes(55)));
        $this->assertSame(46, LiveOddsModel::minute($kickoff, $kickoff->addMinutes(62)->addSeconds(30)));
        $this->assertSame(90, LiveOddsModel::minute($kickoff, $kickoff->addMinutes(110)));
        $this->assertSame(0.0, LiveOddsModel::remainingMinutes($kickoff, $kickoff->addMinutes(130)));
        $this->assertSame(2.0, LiveOddsModel::remainingMinutes($kickoff, $kickoff->addMinutes(130), 2.0), 'Temps additionnel');
        $this->assertSame(49.0, LiveOddsModel::remainingMinutes($kickoff, $kickoff->addMinutes(55), 2.0));
    }

    public function test_odds_include_the_margin_and_are_rounded_down(): void
    {
        $this->assertSame('1.90', substr(LiveOddsModel::odds(0.5, 0.05), 0, 4));
        $this->assertSame('1.010', LiveOddsModel::odds(0.999, 0.08));
    }

    public function test_the_final_correct_score_starts_from_the_current_score(): void
    {
        [$home, $away, $boost] = $this->anchor();
        // 2-1 à la 60e minute environ (36 minutes de jeu restantes).
        $p = LiveOddsModel::correctScores($home, $away, $boost, 2, 1, 36, 3);

        $this->assertArrayHasKey('3-1', $p, 'À 2-1, le match peut encore finir 3-1.');
        $this->assertArrayHasKey('2-1', $p);
        $this->assertArrayHasKey('5-4', $p);
        $this->assertArrayNotHasKey('1-0', $p, 'Un score déjà dépassé n\'existe plus.');
        $this->assertArrayNotHasKey('2-0', $p);
        $this->assertArrayNotHasKey('6-1', $p, 'Au-delà de 3 buts de plus : pas proposé.');
        $this->assertCount(16, $p);
        $this->assertSame('2-1', array_search(max($p), $p, true), 'Le score du moment est l\'issue la plus probable.');
        $this->assertGreaterThan($p['2-2'], $p['3-1'], 'L\'équipe favorite marque plus souvent.');
        $this->assertEqualsWithDelta(1.0, array_sum($p), 0.01);

        // Le temps passe : le score du moment devient de plus en plus probable.
        $late = LiveOddsModel::correctScores($home, $away, $boost, 2, 1, 6, 3);
        $this->assertGreaterThan($p['2-1'], $late['2-1']);
        $this->assertLessThan($p['3-1'], $late['3-1']);

        // Fin du match : seul le score du moment reste.
        $this->assertEqualsWithDelta(1.0, LiveOddsModel::correctScores($home, $away, $boost, 2, 1, 0, 3)['2-1'], 1e-9);
    }
}
