<?php

namespace App\Modules\SportsData\Services;

use Carbon\CarbonInterface;

/**
 * Modèle de cotes « maison » du football en direct (décision D-20, option « Modèle + The Odds »).
 *
 * The Odds API ne donne que le score : pas de faits de jeu. La « tension » du match se déduit donc de trois choses :
 *  1. les cotes d'avant-match (1X2 et plus/moins) ⇒ force offensive de chaque équipe, en buts attendus sur un match
 *     entier (taux de Poisson) et coefficient des nuls (le modèle de Poisson les sous-estime) ;
 *  2. le score du moment ;
 *  3. le temps qui reste : les buts encore attendus = taux × part du match restante.
 * Les probabilités 1X2 et plus/moins se recalculent à chaque seconde avec ces trois éléments. Quand The Odds API
 * envoie des cotes en direct, fit() recale les taux sur elles (le marché corrige le modèle).
 *
 * Horloge estimée depuis l'heure de début prévue (pas de minute de jeu chez le fournisseur) : 1re mi-temps de 47 min
 * (arrêts de jeu compris), pause de 15 min, 2e mi-temps de 49 min ; 96 minutes de jeu effectif en tout.
 * Classe pure (aucune base, aucune config) : testée seule.
 */
final class LiveOddsModel
{
    public const FIRST_HALF_MINUTES = 47;

    public const BREAK_MINUTES = 15;

    public const SECOND_HALF_MINUTES = 49;

    public const PLAYING_MINUTES = self::FIRST_HALF_MINUTES + self::SECOND_HALF_MINUTES;

    /** Buts supplémentaires calculés par équipe (au-delà, probabilité négligeable). */
    private const MAX_GOALS = 10;

    /** Coefficient des nuls borné : une cote d'avant-match aberrante ne déforme pas tout le match. */
    private const MIN_DRAW_BOOST = 0.8;

    private const MAX_DRAW_BOOST = 1.6;

    /** Minutes de jeu effectif restantes (0 à 96) d'après l'heure de début prévue. */
    public static function remainingMinutes(CarbonInterface $startsAt, CarbonInterface $now): float
    {
        $elapsed = max(0.0, ($now->getTimestamp() - $startsAt->getTimestamp()) / 60);

        return match (true) {
            $elapsed <= self::FIRST_HALF_MINUTES => self::FIRST_HALF_MINUTES - $elapsed + self::SECOND_HALF_MINUTES,
            $elapsed <= self::FIRST_HALF_MINUTES + self::BREAK_MINUTES => (float) self::SECOND_HALF_MINUTES,
            default => max(0.0, self::PLAYING_MINUTES + self::BREAK_MINUTES - $elapsed),
        };
    }

    /** Minute de jeu estimée affichée (1 à 90), null pendant la pause. */
    public static function minute(CarbonInterface $startsAt, CarbonInterface $now): ?int
    {
        $elapsed = max(0.0, ($now->getTimestamp() - $startsAt->getTimestamp()) / 60);

        return match (true) {
            $elapsed <= self::FIRST_HALF_MINUTES => min(45, (int) floor($elapsed) + 1),
            $elapsed <= self::FIRST_HALF_MINUTES + self::BREAK_MINUTES => null,
            default => min(90, 45 + (int) floor($elapsed - self::FIRST_HALF_MINUTES - self::BREAK_MINUTES) + 1),
        };
    }

    /**
     * Probabilités justes du 1X2 et du plus/moins (sans marge).
     *
     * @param  float  $homeRate  buts attendus de l'équipe à domicile sur un match entier
     * @param  float  $drawBoost  coefficient des nuls de l'avant-match (1 = Poisson pur), atténué avec le temps
     * @param  ?float  $line  ligne du plus/moins (null : pas de plus/moins)
     * @return array{home: float, draw: float, away: float, over: ?float, under: ?float} « plus » et « moins » sans le
     *                                                                                   remboursement d'une ligne entière
     */
    public static function probabilities(float $homeRate, float $awayRate, float $drawBoost, int $homeGoals, int $awayGoals,
        float $remainingMinutes, ?float $line): array
    {
        $share = max(0.0, min(1.0, $remainingMinutes / self::PLAYING_MINUTES));
        $grid = self::grid($homeRate * $share, $awayRate * $share);

        $home = $draw = $away = 0.0;
        $over = $under = 0.0;
        foreach ($grid as $x => $row) {
            foreach ($row as $y => $p) {
                $diff = ($homeGoals + $x) - ($awayGoals + $y);
                $diff > 0 ? $home += $p : ($diff === 0 ? $draw += $p : $away += $p);
                if ($line !== null) {
                    $total = $homeGoals + $awayGoals + $x + $y;
                    if ($total > $line) {
                        $over += $p;
                    } elseif ($total < $line) {
                        $under += $p;
                    }
                }
            }
        }

        // Le surplus de nuls de l'avant-match s'efface à mesure que le match avance.
        $draw *= 1 + ($drawBoost - 1) * $share;
        $sum = $home + $draw + $away;

        return [
            'home' => $home / $sum,
            'draw' => $draw / $sum,
            'away' => $away / $sum,
            'over' => $line === null || $over + $under <= 0 ? null : $over / ($over + $under),
            'under' => $line === null || $over + $under <= 0 ? null : $under / ($over + $under),
        ];
    }

    /**
     * Taux de Poisson (match entier) qui reproduisent des probabilités justes observées à un instant du match :
     * cotes d'avant-match (score 0-0, 96 minutes restantes) ou cotes en direct de The Odds API.
     *
     * @param  array{home: float, draw: float, away: float}  $result  1X2 juste (somme 1)
     * @param  ?float  $line  ligne du plus/moins, null si absente (le total attendu vient alors de $fallbackTotal)
     * @param  ?float  $over  probabilité juste du « plus », sans le remboursement
     * @param  float  $fallbackTotal  buts attendus sur un match entier quand le plus/moins ne dit rien
     * @return array{0: float, 1: float}|null null : plus assez de temps pour en tirer quoi que ce soit
     */
    public static function fit(array $result, ?float $line, ?float $over, int $homeGoals, int $awayGoals,
        float $remainingMinutes, float $fallbackTotal): ?array
    {
        $share = $remainingMinutes / self::PLAYING_MINUTES;
        if ($share < 0.05) {
            return null;
        }

        // Buts encore attendus : P(total final > ligne) croît avec eux. Ligne déjà dépassée : le marché ne dit plus rien.
        $current = $homeGoals + $awayGoals;
        $total = $fallbackTotal * $share;
        if ($line !== null && $over !== null && $current < $line && $over > 0.001 && $over < 0.999) {
            $total = self::bisect(0.01, 9.0 * $share + 0.5, fn (float $mu) => self::overGivenTotal($mu, $line - $current) - $over);
        }

        // Partage entre les équipes : l'écart P(domicile) − P(extérieur) croît avec la part de l'équipe à domicile.
        $target = $result['home'] - $result['away'];
        $part = self::bisect(0.01, 0.99, function (float $s) use ($total, $homeGoals, $awayGoals, $target) {
            $p = self::probabilities($total * $s, $total * (1 - $s), 1.0, $homeGoals, $awayGoals, self::PLAYING_MINUTES, null);

            return $p['home'] - $p['away'] - $target;
        });

        return [$total * $part / $share, $total * (1 - $part) / $share];
    }

    /**
     * Coefficient des nuls de l'avant-match : nul du 1X2 juste ÷ nul du modèle de Poisson.
     *
     * @param  array{home: float, draw: float, away: float}  $result
     */
    public static function drawBoost(array $result, float $homeRate, float $awayRate): float
    {
        $model = self::probabilities($homeRate, $awayRate, 1.0, 0, 0, self::PLAYING_MINUTES, null)['draw'];

        return $model > 0 ? max(self::MIN_DRAW_BOOST, min(self::MAX_DRAW_BOOST, $result['draw'] / $model)) : 1.0;
    }

    /**
     * Cote proposée : 1 / (probabilité × (1 + marge)), arrondie vers le bas à 2 décimales, au moins 1,01.
     */
    public static function odds(float $probability, float $margin): string
    {
        $odds = 1 / (max($probability, 1e-9) * (1 + $margin));
        $odds = max(1.01, floor(round($odds * 100, 6)) / 100);

        return number_format($odds, 3, '.', '');
    }

    /** P(buts supplémentaires > reste de la ligne) pour un total de Poisson μ, sans le remboursement. */
    private static function overGivenTotal(float $mu, float $line): float
    {
        $below = $at = 0.0;
        for ($k = 0; $k <= (int) floor($line); $k++) {
            $pk = self::poisson($mu, $k);
            $k == $line ? $at = $pk : $below += $pk;
        }
        $above = max(0.0, 1 - $below - $at);

        return $above + $below > 0 ? $above / ($above + $below) : 0.0;
    }

    /** @return array<int, array<int, float>> probabilité de chaque nombre de buts supplémentaires [domicile][extérieur] */
    private static function grid(float $home, float $away): array
    {
        $h = $a = [];
        for ($k = 0; $k <= self::MAX_GOALS; $k++) {
            $h[$k] = self::poisson($home, $k);
            $a[$k] = self::poisson($away, $k);
        }

        $grid = [];
        foreach ($h as $x => $ph) {
            foreach ($a as $y => $pa) {
                $grid[$x][$y] = $ph * $pa;
            }
        }

        return $grid;
    }

    private static function poisson(float $mu, int $k): float
    {
        $p = exp(-$mu);
        for ($i = 1; $i <= $k; $i++) {
            $p *= $mu / $i;
        }

        return $p;
    }

    /** Zéro d'une fonction croissante sur [low, high] (bornes renvoyées si hors de portée). */
    private static function bisect(float $low, float $high, callable $f): float
    {
        if ($f($low) >= 0) {
            return $low;
        }
        if ($f($high) <= 0) {
            return $high;
        }
        for ($i = 0; $i < 50; $i++) {
            $mid = ($low + $high) / 2;
            $f($mid) < 0 ? $low = $mid : $high = $mid;
        }

        return ($low + $high) / 2;
    }
}
