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

    private function unknown(string $marketKey, string $selectionKey): never
    {
        throw new InvalidArgumentException("Sélection non réglable : {$marketKey}/{$selectionKey}.");
    }
}
