<?php

namespace App\Modules\Settlement\Services;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use InvalidArgumentException;

/**
 * Issue d'une sélection d'après le résultat d'un match, pour les marchés du catalogue (CatalogueSyncService::MARKETS).
 *
 * - Match annulé : sélection annulée (void, cote 1 dans un combiné) — politique D-05.
 * - Plus/moins : la ligne est celle figée sur le ticket ; un total égal à une ligne entière ("3") est annulé.
 * - Handicap : même règle, sur l'écart de buts ; remboursé si nul : le nul annule la sélection.
 * - Marchés à plusieurs lignes (alternate_*, team_totals) : la ligne est dans la clé de la sélection, figée sur le ticket.
 */
class OutcomeResolver
{
    /** @throws InvalidArgumentException marché ou sélection non pris en charge */
    public function resolve(string $marketKey, ?string $line, string $selectionKey, Result $result): BetSelectionStatus
    {
        if ($result->status === ResultStatus::Cancelled) {
            return BetSelectionStatus::Void;
        }

        $home = (int) $result->home_score;
        $away = (int) $result->away_score;
        $winner = $home <=> $away;

        $won = match ($marketKey) {
            'h2h' => match ($selectionKey) {
                'home' => $winner > 0,
                'draw' => $winner === 0,
                'away' => $winner < 0,
                default => $this->unknown($marketKey, $selectionKey),
            },
            'double_chance' => match ($selectionKey) {
                'home_draw' => $winner >= 0,
                'home_away' => $winner !== 0,
                'draw_away' => $winner <= 0,
                default => $this->unknown($marketKey, $selectionKey),
            },
            'btts' => match ($selectionKey) {
                'yes' => $home > 0 && $away > 0,
                'no' => $home === 0 || $away === 0,
                default => $this->unknown($marketKey, $selectionKey),
            },
            'totals' => $this->totals($line, $selectionKey, $home + $away),
            // Score exact : clé "domicile-extérieur", ex. "2-1".
            'correct_score' => preg_match('/^\d+-\d+$/', $selectionKey) === 1
                ? $selectionKey === "{$home}-{$away}"
                : $this->unknown($marketKey, $selectionKey),
            // Remboursé si nul : le nul annule la sélection.
            'draw_no_bet' => match ($selectionKey) {
                'home' => $winner === 0 ? null : $winner > 0,
                'away' => $winner === 0 ? null : $winner < 0,
                default => $this->unknown($marketKey, $selectionKey),
            },
            // Handicap : la ligne figée sur le ticket est celle de l'équipe à domicile.
            'spreads' => match ($selectionKey) {
                'home' => $this->handicap('home', $this->line($line), $home, $away),
                'away' => $this->handicap('away', bcmul($this->line($line), '-1', 2), $home, $away),
                default => $this->unknown($marketKey, $selectionKey),
            },
            // Marchés à plusieurs lignes : la ligne est dans la clé de la sélection.
            'alternate_spreads' => preg_match('/^(home|away)_([+-]?\d+(?:\.\d+)?)$/', $selectionKey, $m) === 1
                ? $this->handicap($m[1], ltrim($m[2], '+'), $home, $away)
                : $this->unknown($marketKey, $selectionKey),
            'alternate_totals' => preg_match('/^(over|under)_(\d+(?:\.\d+)?)$/', $selectionKey, $m) === 1
                ? $this->totals($m[2], $m[1], $home + $away)
                : $this->unknown($marketKey, $selectionKey),
            'team_totals', 'alternate_team_totals' => preg_match('/^(home|away)_(over|under)_(\d+(?:\.\d+)?)$/', $selectionKey, $m) === 1
                ? $this->totals($m[3], $m[2], $m[1] === 'home' ? $home : $away)
                : $this->unknown($marketKey, $selectionKey),
            'odd_even' => match ($selectionKey) {
                'odd' => ($home + $away) % 2 === 1,
                'even' => ($home + $away) % 2 === 0,
                default => $this->unknown($marketKey, $selectionKey),
            },
            default => $this->unknown($marketKey, $selectionKey),
        };

        if ($won === null) {
            return BetSelectionStatus::Void;
        }

        return $won ? BetSelectionStatus::Won : BetSelectionStatus::Lost;
    }

    /** @return bool|null null : total égal à la ligne (remboursé) */
    private function totals(?string $line, string $selectionKey, int $total): ?bool
    {
        if ($line === null || ! is_numeric($line)) {
            throw new InvalidArgumentException('Marché totals sans ligne.');
        }

        $comparison = bccomp((string) $total, $line, 2);

        return match ($selectionKey) {
            'over' => $comparison === 0 ? null : $comparison > 0,
            'under' => $comparison === 0 ? null : $comparison < 0,
            default => $this->unknown('totals', $selectionKey),
        };
    }

    /**
     * Handicap : écart de l'équipe visée + sa ligne ; égalité exacte (ligne entière) remboursée.
     *
     * @return bool|null null : remboursé
     */
    private function handicap(string $side, string $line, int $home, int $away): ?bool
    {
        $margin = $side === 'home' ? $home - $away : $away - $home;
        $comparison = bccomp(bcadd((string) $margin, $line, 2), '0', 2);

        return $comparison === 0 ? null : $comparison > 0;
    }

    private function line(?string $line): string
    {
        if ($line === null || ! is_numeric($line)) {
            throw new InvalidArgumentException('Marché handicap sans ligne.');
        }

        return $line;
    }

    private function unknown(string $marketKey, string $selectionKey): never
    {
        throw new InvalidArgumentException("Sélection non réglable : {$marketKey}/{$selectionKey}.");
    }
}
