<?php

namespace App\Modules\Settlement\Services;

/** Bilan d'un passage de règlement (affiché par la commande, journalisé). */
final class SettlementReport
{
    /** Sélections de ticket réglées. */
    public int $selections = 0;

    /** @var array{won: int, lost: int, void: int} tickets réglés par issue */
    public array $tickets = ['won' => 0, 'lost' => 0, 'void' => 0];

    /** Total versé (gains et remboursements), en unité mineure. */
    public int $paidMinor = 0;

    /** Sélections impossibles à régler (marché non pris en charge), journalisées. */
    public int $failed = 0;

    public function merge(self $other): void
    {
        $this->selections += $other->selections;
        $this->paidMinor += $other->paidMinor;
        $this->failed += $other->failed;
        foreach ($other->tickets as $status => $count) {
            $this->tickets[$status] += $count;
        }
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['selections' => $this->selections, ...$this->tickets, 'paid_minor' => $this->paidMinor, 'failed' => $this->failed];
    }
}
