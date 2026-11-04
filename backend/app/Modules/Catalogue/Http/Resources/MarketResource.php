<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\SportsData\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Marché du catalogue : 1X2 (h2h), double chance, plus/moins (totals, avec sa ligne, ex. "2.5"), les deux équipes marquent (btts),
 * score exact (correct_score, sélections "2-1"), remboursé si nul, handicap (ligne de l'équipe à domicile), pair/impair
 * et marchés à plusieurs lignes (alternate_*, team_totals : ligne dans la clé de la sélection, "over_2.5").
 * L'événement (compétition et sport chargés) est rattaché par EventResource pour le libellé.
 *
 * @mixin Market
 */
class MarketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'key' => $this->key,
            'name' => self::marketName($this->key, $this->event?->competition?->sport?->slug),
            // Ligne du plus/moins en chaîne décimale (ex. "2.5") ; null pour le 1X2.
            'line' => $this->line,
            // À renvoyer avec la sélection lors du pari : le serveur refuse une version périmée (P4-03).
            'odds_version' => $this->odds_version,
            'odds_updated_at' => $this->odds_updated_at?->toIso8601String(),
            // false : cote trop ancienne (règle de fraîcheur), affichée mais non pariable.
            'bettable' => $this->isBettable(),
            'selections' => $this->selections->map(fn ($s) => [
                'id' => (string) $s->id,
                'key' => $s->key,
                'label' => self::selectionLabel($s->key, $this->line),
                'name' => $s->name,
                // Cote en chaîne décimale (ex. "2.10") : jamais de nombre flottant.
                'odds' => self::odds($s->odds),
            ])->values(),
        ];
    }

    /** Libellé d'un marché (aussi affiché dans l'historique des tickets). */
    public static function marketName(string $key, ?string $sportSlug): string
    {
        $goals = $sportSlug === 'soccer';

        return match ($key) {
            'h2h' => 'Résultat du match (1X2)',
            'double_chance' => 'Double chance',
            'draw_no_bet' => 'Remboursé si match nul',
            'btts' => 'Les deux équipes marquent',
            'correct_score' => 'Score exact',
            'totals' => $goals ? 'Nombre de buts' : 'Total de points',
            'alternate_totals' => $goals ? 'Nombre de buts (autres lignes)' : 'Total de points (autres lignes)',
            'spreads' => 'Handicap',
            'alternate_spreads' => 'Handicap (autres lignes)',
            'team_totals' => $goals ? 'Buts par équipe' : 'Points par équipe',
            'alternate_team_totals' => $goals ? 'Buts par équipe (autres lignes)' : 'Points par équipe (autres lignes)',
            'odd_even' => $goals ? 'Nombre de buts pair ou impair' : 'Total pair ou impair',
            default => $key,
        };
    }

    /**
     * Libellé court d'une issue, seul texte du bouton de cote sur la page du match : 1, X, 2 ; 1X, 12, X2 ; > 2.5 / < 2.5 ;
     * Oui / Non ; handicap 1 (-0.5) / 2 (+0.5) ; buts par équipe 1 > 1.5 / 2 < 0.5 ; Impair / Pair ; score exact 2-1 (clé).
     * $line est la ligne du marché (null hors plus/moins et handicap).
     */
    public static function selectionLabel(string $key, ?string $line): string
    {
        $team = fn (string $side) => $side === 'home' ? '1' : '2';
        $signed = fn (string $value) => bccomp($value, '0', 2) > 0 ? "+{$value}" : $value;

        return match (true) {
            // Handicap : la ligne du marché est celle de l'équipe à domicile.
            $key === 'home' => $line === null ? '1' : "1 ({$signed($line)})",
            $key === 'away' => $line === null ? '2' : '2 ('.$signed(self::opposite($line)).')',
            $key === 'draw' => 'X',
            $key === 'home_draw' => '1X',
            $key === 'home_away' => '12',
            $key === 'draw_away' => 'X2',
            $key === 'over' => "> {$line}",
            $key === 'under' => "< {$line}",
            $key === 'yes' => 'Oui',
            $key === 'no' => 'Non',
            $key === 'odd' => 'Impair',
            $key === 'even' => 'Pair',
            (bool) preg_match('/^(over|under)_(.+)$/', $key, $m) => ($m[1] === 'over' ? '> ' : '< ').$m[2],
            (bool) preg_match('/^(home|away)_(over|under)_(.+)$/', $key, $m) => $team($m[1]).($m[2] === 'over' ? ' > ' : ' < ').$m[3],
            (bool) preg_match('/^(home|away)_([+-]?[\d.]+)$/', $key, $m) => "{$team($m[1])} ({$m[2]})",
            default => $key,
        };
    }

    /** Ligne de l'équipe à l'extérieur : "-0.5" → "0.5", "1" → "-1", "0" → "0". */
    private static function opposite(string $line): string
    {
        if (bccomp($line, '0', 2) === 0) {
            return '0';
        }

        return rtrim(rtrim(bcmul($line, '-1', 2), '0'), '.');
    }

    /** "2.100" → "2.10", "1.005" → "1.005". */
    public static function odds(string $odds): string
    {
        return str_ends_with($odds, '0') ? substr($odds, 0, -1) : $odds;
    }
}
