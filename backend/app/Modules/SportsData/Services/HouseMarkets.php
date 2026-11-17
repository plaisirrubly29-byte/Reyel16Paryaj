<?php

namespace App\Modules\SportsData\Services;

/**
 * Marchés maison de REYEL 16 (V2-D10) : paris du football que The Odds API ne fournit pas, cotés par nous et réglés
 * sur le score final (comme le 1X2). Règle : tout marché fourni par The Odds API est pris tel quel ; on n'ajoute
 * ici que ce qu'il n'a pas (pas de doublon, ex. « l'équipe marque » = buts par équipe plus/moins de 0,5).
 *
 * Calcul des cotes (modèle de Poisson) :
 *  1. probabilités justes du 1X2 et du plus/moins (moyenne des bookmakers, sans leur marge) ;
 *  2. buts attendus du match (μ) tels que P(total > ligne) = probabilité juste du « plus » ;
 *  3. partage de μ entre les deux équipes tel que P(victoire domicile) − P(victoire extérieur) soit celui du 1X2 ;
 *  4. probabilité de chaque score (0-0, 1-0…, buts indépendants de chaque équipe), recalée pour retrouver
 *     exactement le 1X2 (le modèle sous-estime les nuls), puis de chaque pari ;
 *  5. cote = 1 / (probabilité × (1 + marge)), arrondie vers le bas, entre 1,01 et max_odds ; une issue trop
 *     improbable (< 0,5 %) n'est pas proposée.
 * Marge plus forte et mise maximale plus basse que les marchés des bookmakers (config sports_data.house_markets).
 */
final class HouseMarkets
{
    public const EXACT_GOALS = 'r16_exact_goals';

    public const GOAL_RANGE = 'r16_goal_range';

    public const WIN_TO_NIL = 'r16_win_to_nil';

    public const WINNING_MARGIN = 'r16_winning_margin';

    public const RESULT_TOTAL = 'r16_result_total';

    public const KEYS = [
        self::EXACT_GOALS, self::GOAL_RANGE, self::WIN_TO_NIL, self::WINNING_MARGIN, self::RESULT_TOTAL,
    ];

    /** Ligne du marché « résultat et plus/moins de buts ». */
    public const RESULT_TOTAL_LINE = 2.5;

    /** Scores calculés jusqu'à 10 buts par équipe (au-delà, probabilité négligeable). */
    private const MAX_GOALS = 10;

    /** Issue plus improbable : non proposée (cote trop haute, trop sensible à l'erreur du modèle). */
    private const MIN_PROBABILITY = 0.005;

    public static function enabled(): bool
    {
        return (bool) config('sports_data.house_markets.enabled', false);
    }

    public static function isHouse(string $marketKey): bool
    {
        return in_array($marketKey, self::KEYS, true);
    }

    /**
     * Cotes des marchés maison d'un match.
     *
     * @param  array{home: float, draw: float, away: float}  $result  probabilités justes du 1X2 (somme 1)
     * @param  float  $over  probabilité juste du « plus » sur la ligne $line (sans le remboursement d'une ligne entière)
     * @return array<string, array<string, array{name: string, odds: string}>> clé du marché => sélections
     */
    public static function build(array $result, float $line, float $over, string $homeTeam, string $awayTeam, float $margin): array
    {
        [$home, $away] = self::expectedGoals($result, $line, $over);
        $scores = self::calibrate(self::scores($home, $away), $result);
        $line2 = self::RESULT_TOTAL_LINE;

        // Noms des sélections ; leur probabilité vient de won(), la règle même du règlement.
        $markets = [
            self::EXACT_GOALS => [
                'goals_0' => '0 but', 'goals_1' => '1 but', 'goals_2' => '2 buts', 'goals_3' => '3 buts',
                'goals_4' => '4 buts', 'goals_5plus' => '5 buts ou plus',
            ],
            self::GOAL_RANGE => ['range_0_1' => '0 ou 1 but', 'range_2_3' => '2 ou 3 buts', 'range_4plus' => '4 buts ou plus'],
            self::WIN_TO_NIL => [
                'home_clean' => "{$homeTeam} gagne sans encaisser",
                'away_clean' => "{$awayTeam} gagne sans encaisser",
                'neither' => 'Aucune des deux',
            ],
            self::WINNING_MARGIN => [
                'home_by_1' => "{$homeTeam} par 1 but", 'home_by_2' => "{$homeTeam} par 2 buts",
                'home_by_3plus' => "{$homeTeam} par 3 buts ou plus", 'draw' => 'Match nul',
                'away_by_1' => "{$awayTeam} par 1 but", 'away_by_2' => "{$awayTeam} par 2 buts",
                'away_by_3plus' => "{$awayTeam} par 3 buts ou plus",
            ],
            self::RESULT_TOTAL => [
                'home_and_over' => "{$homeTeam} et plus de {$line2} buts", 'home_and_under' => "{$homeTeam} et moins de {$line2} buts",
                'draw_and_over' => "Nul et plus de {$line2} buts", 'draw_and_under' => "Nul et moins de {$line2} buts",
                'away_and_over' => "{$awayTeam} et plus de {$line2} buts", 'away_and_under' => "{$awayTeam} et moins de {$line2} buts",
            ],
        ];

        $priced = [];
        foreach ($markets as $key => $selections) {
            $odds = [];
            foreach ($selections as $selectionKey => $name) {
                $probability = self::sum($scores, fn (int $h, int $a) => self::won($key, $selectionKey, $h, $a) === true);
                if ($probability >= self::MIN_PROBABILITY) {
                    $odds[$selectionKey] = ['name' => $name, 'odds' => self::odds($probability, $margin)];
                }
            }
            if (count($odds) >= 2) {
                $priced[$key] = $odds;
            }
        }

        return $priced;
    }

    /** Une équipe gagne sans encaisser de but. */
    public static function cleanWin(int $home, int $away): bool
    {
        return ($home > 0 && $away === 0) || ($away > 0 && $home === 0);
    }

    /**
     * Issue d'une sélection d'un marché maison d'après le score final.
     *
     * @return bool|null null : sélection inconnue
     */
    public static function won(string $marketKey, string $selectionKey, int $home, int $away): ?bool
    {
        $total = $home + $away;
        $diff = $home - $away;
        $line = self::RESULT_TOTAL_LINE;

        return match ($marketKey) {
            self::EXACT_GOALS => match (true) {
                $selectionKey === 'goals_5plus' => $total >= 5,
                (bool) preg_match('/^goals_([0-4])$/', $selectionKey, $m) => $total === (int) $m[1],
                default => null,
            },
            self::GOAL_RANGE => match ($selectionKey) {
                'range_0_1' => $total <= 1,
                'range_2_3' => $total >= 2 && $total <= 3,
                'range_4plus' => $total >= 4,
                default => null,
            },
            self::WIN_TO_NIL => match ($selectionKey) {
                'home_clean' => $home > 0 && $away === 0,
                'away_clean' => $away > 0 && $home === 0,
                'neither' => ! self::cleanWin($home, $away),
                default => null,
            },
            self::WINNING_MARGIN => match ($selectionKey) {
                'home_by_1' => $diff === 1,
                'home_by_2' => $diff === 2,
                'home_by_3plus' => $diff >= 3,
                'draw' => $diff === 0,
                'away_by_1' => $diff === -1,
                'away_by_2' => $diff === -2,
                'away_by_3plus' => $diff <= -3,
                default => null,
            },
            self::RESULT_TOTAL => preg_match('/^(home|draw|away)_and_(over|under)$/', $selectionKey, $m) === 1
                ? (['home' => $diff > 0, 'draw' => $diff === 0, 'away' => $diff < 0][$m[1]])
                    && ($m[2] === 'over' ? $total > $line : $total < $line)
                : null,
            default => null,
        };
    }

    /**
     * Buts attendus de chaque équipe, ajustés sur le plus/moins puis sur le 1X2.
     *
     * @param  array{home: float, draw: float, away: float}  $result
     * @return array{0: float, 1: float}
     */
    public static function expectedGoals(array $result, float $line, float $over): array
    {
        // Total attendu : P(plus) croît avec μ.
        $total = self::bisect(0.1, 9.0, fn (float $mu) => self::overProbability($mu, $line) - $over);

        // Part de l'équipe à domicile : l'écart P(domicile) − P(extérieur) croît avec elle.
        $target = $result['home'] - $result['away'];
        $share = self::bisect(0.01, 0.99, function (float $s) use ($total, $target) {
            $scores = self::scores($total * $s, $total * (1 - $s));

            return self::sum($scores, fn ($h, $a) => $h > $a) - self::sum($scores, fn ($h, $a) => $h < $a) - $target;
        });

        return [$total * $share, $total * (1 - $share)];
    }

    /** P(total > ligne) pour un total de Poisson μ ; une ligne entière exclut le remboursement (égalité). */
    private static function overProbability(float $mu, float $line): float
    {
        $below = 0.0;
        $at = 0.0;
        for ($k = 0; $k <= (int) floor($line); $k++) {
            $pk = self::poisson($mu, $k);
            if ($k == $line) {
                $at = $pk;
            } else {
                $below += $pk;
            }
        }
        $above = max(0.0, 1 - $below - $at);

        return $above + $below > 0 ? $above / ($above + $below) : 0.0;
    }

    /** @return array<int, array<int, float>> probabilité de chaque score [domicile][extérieur] */
    private static function scores(float $home, float $away): array
    {
        $scores = [];
        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                $scores[$h][$a] = self::poisson($home, $h) * self::poisson($away, $a);
            }
        }

        return $scores;
    }

    /**
     * Recale les scores sur le 1X2 des bookmakers : le modèle de Poisson sous-estime les nuls. Chaque score est
     * multiplié pour que victoire domicile, nul et victoire extérieur aient exactement les probabilités du 1X2 ;
     * sans cela, « Match nul » de l'écart de victoire serait mieux payé que le nul du 1X2.
     *
     * @param  array<int, array<int, float>>  $scores
     * @param  array{home: float, draw: float, away: float}  $result
     * @return array<int, array<int, float>>
     */
    private static function calibrate(array $scores, array $result): array
    {
        $outcome = fn (int $h, int $a) => $h > $a ? 'home' : ($h === $a ? 'draw' : 'away');
        $model = ['home' => 0.0, 'draw' => 0.0, 'away' => 0.0];
        foreach ($scores as $h => $row) {
            foreach ($row as $a => $p) {
                $model[$outcome($h, $a)] += $p;
            }
        }

        foreach ($scores as $h => $row) {
            foreach ($row as $a => $p) {
                $key = $outcome($h, $a);
                $scores[$h][$a] = $model[$key] > 0 ? $p * $result[$key] / $model[$key] : 0.0;
            }
        }

        return $scores;
    }

    /** @param array<int, array<int, float>> $scores */
    private static function sum(array $scores, callable $won): float
    {
        $total = 0.0;
        foreach ($scores as $h => $row) {
            foreach ($row as $a => $p) {
                if ($won($h, $a)) {
                    $total += $p;
                }
            }
        }

        return $total;
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

    private static function odds(float $probability, float $margin): string
    {
        $odds = 1 / ($probability * (1 + $margin));
        $odds = floor(round($odds * 100, 6)) / 100;
        $odds = max(1.01, min($odds, (float) config('sports_data.house_markets.max_odds', 100)));

        return number_format($odds, 3, '.', '');
    }
}
