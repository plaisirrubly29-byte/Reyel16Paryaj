<?php

namespace App\Modules\SportsData\Services;

/**
 * Marge de l'opérateur appliquée aux cotes (V2-D03, décision D-18, option C), avec l'argent réel seulement.
 *
 * Pour un marché, chaque bookmaker donne une cote par sélection. Méthode proportionnelle :
 *  1. probabilité implicite = 1 / cote, chez chaque bookmaker ; on retire sa marge en ramenant la somme au nombre de
 *     sélections gagnantes (1 en général, 2 pour la double chance) ;
 *  2. probabilité juste = moyenne des bookmakers ;
 *  3. cote = 1 / (probabilité juste × (1 + marge)), arrondie à 2 décimales vers le bas, jamais sous 1,01 ni au-dessus
 *     de la meilleure cote des bookmakers suivis.
 * Moins de min_bookmakers sources : les cotes du bookmaker de référence restent telles quelles (CO-5).
 */
final class OddsMargin
{
    /** Marchés principaux (5 % par défaut) ; les autres sont secondaires (8 %), le 1X2 du football a son propre taux. */
    private const MAIN_MARKETS = ['h2h', 'totals', 'double_chance', 'btts', 'draw_no_bet'];

    /** Marge appliquée : seulement avec l'argent réel (CO-3) et si elle n'est pas coupée. */
    public static function enabled(): bool
    {
        return ! config('wallet.simulated') && (bool) config('sports_data.margin.enabled', true);
    }

    /** Taux de marge d'un marché, selon le sport (clé du fournisseur, ex. soccer_epl) et le direct (CO-2). */
    public static function rate(string $sportKey, string $marketKey, bool $live): float
    {
        $config = config('sports_data.margin');
        $rate = match (true) {
            $marketKey === 'h2h' && str_starts_with($sportKey, 'soccer') => (float) $config['football_h2h'],
            in_array($marketKey, self::MAIN_MARKETS, true) => (float) $config['main'],
            default => (float) $config['secondary'],
        };

        return $rate + ($live ? (float) $config['live_extra'] : 0.0);
    }

    /**
     * Cotes avec la marge de l'opérateur.
     *
     * @param  array<string, array{name: string, odds: string}>  $selections  sélections retenues (bookmaker de référence)
     * @param  list<array<string, string>>  $sources  cotes de chaque bookmaker pour ce marché (clé de sélection => cote),
     *                                                bookmaker de référence compris ; mêmes clés que $selections
     * @return array<string, array{name: string, odds: string}>
     */
    public static function apply(array $selections, array $sources, float $rate): array
    {
        $keys = array_keys($selections);
        $sources = array_values(array_filter($sources, fn (array $odds) => self::sameKeys($odds, $keys)));
        if (count($sources) < (int) config('sports_data.margin.min_bookmakers', 2) || $rate <= 0) {
            return $selections;
        }

        $fair = array_fill_keys($keys, 0.0);
        $best = array_fill_keys($keys, 0.0);
        foreach ($sources as $odds) {
            $implied = array_map(fn (string $o) => 1 / (float) $o, $odds);
            $sum = array_sum($implied);
            // Nombre de sélections gagnantes : 1 (1X2, plus/moins…), 2 pour la double chance (somme proche de 2).
            $winners = max(1, (int) round($sum));
            foreach ($keys as $key) {
                $fair[$key] += $implied[$key] / $sum * $winners / count($sources);
                $best[$key] = max($best[$key], (float) $odds[$key]);
            }
        }

        foreach ($keys as $key) {
            $odds = 1 / ($fair[$key] * (1 + $rate));
            // Arrondi vers le bas à 2 décimales (le round à 6 décimales évite 2.09 × 100 = 208,999…).
            $odds = floor(round($odds * 100, 6)) / 100;
            $odds = max(1.01, min($odds, $best[$key]));
            $selections[$key]['odds'] = number_format($odds, 3, '.', '');
        }

        return $selections;
    }

    /** @param list<string> $keys */
    private static function sameKeys(array $odds, array $keys): bool
    {
        $have = array_keys($odds);
        sort($have);
        sort($keys);

        return $have === $keys;
    }
}
