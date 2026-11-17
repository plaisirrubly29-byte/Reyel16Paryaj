<?php

namespace App\Modules\SportsData\Services;

/**
 * Clés de marché du fournisseur : type de pari (base) et ce qu'il compte (mesure).
 *
 * - « h2h », « totals »… : buts (ou points) du match entier, réglés sur le score final.
 * - suffixe « _h1 » : même pari sur la 1re mi-temps (« totals_h1 ») ; « halftime_fulltime » : mi-temps / fin de match.
 * - suffixe « _corners » ou « _cards » : même pari sur le nombre de corners ou de cartons (« alternate_totals_corners »).
 * Les mesures autres que les buts du match se règlent avec les statistiques du match (MatchStatistics).
 */
final class MarketKeys
{
    public const GOALS = 'goals';

    public const FIRST_HALF = 'first_half';

    public const CORNERS = 'corners';

    public const CARDS = 'cards';

    /** Mi-temps / fin de match : score de la 1re mi-temps et score final. */
    public const HALFTIME_FULLTIME = 'halftime_fulltime';

    /** Marchés réglés sur le score final. */
    public const FULL_TIME = [
        'h2h', 'double_chance', 'draw_no_bet', 'totals', 'alternate_totals', 'btts', 'correct_score',
        'spreads', 'alternate_spreads', 'team_totals', 'alternate_team_totals', 'odd_even',
    ];

    /** Marchés réglés avec les statistiques du match (V2-D09) : 1re mi-temps, corners, cartons. */
    public const STATISTICS = [
        'h2h_h1', 'double_chance_h1', 'draw_no_bet_h1', 'totals_h1', 'btts_h1', 'correct_score_h1', self::HALFTIME_FULLTIME,
        'alternate_totals_corners', 'alternate_spreads_corners', 'alternate_team_totals_corners',
        'alternate_totals_cards', 'alternate_spreads_cards',
    ];

    /** @return list<string> tous les marchés pris en charge */
    public static function all(): array
    {
        return [...self::FULL_TIME, ...self::STATISTICS];
    }

    /** Type de pari : « totals_h1 » → « totals », « alternate_totals_corners » → « alternate_totals ». */
    public static function base(string $key): string
    {
        return self::split($key)[0];
    }

    /** Ce que compte le marché : goals, first_half, corners, cards (ou halftime_fulltime). */
    public static function measure(string $key): string
    {
        return self::split($key)[1];
    }

    /** true : le règlement attend les statistiques du match (le score final ne suffit pas). */
    public static function needsStatistics(string $key): bool
    {
        return self::measure($key) !== self::GOALS;
    }

    /** @return array{0: string, 1: string} [base, mesure] */
    private static function split(string $key): array
    {
        return match (true) {
            $key === self::HALFTIME_FULLTIME => [self::HALFTIME_FULLTIME, self::HALFTIME_FULLTIME],
            str_ends_with($key, '_h1') => [substr($key, 0, -3), self::FIRST_HALF],
            str_ends_with($key, '_corners') => [substr($key, 0, -8), self::CORNERS],
            str_ends_with($key, '_cards') => [substr($key, 0, -6), self::CARDS],
            default => [$key, self::GOALS],
        };
    }
}
