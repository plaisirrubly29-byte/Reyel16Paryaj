<?php

namespace App\Modules\Lottery\Services;

use App\Modules\Lottery\Enums\LotteryTicketStatus;

/** Bilan d'un passage de règlement : nombre de fiches par statut final. */
class LotterySettlementReport
{
    /** @var array<string, int> */
    public array $counts = ['won' => 0, 'lost' => 0, 'void' => 0];

    public function count(LotteryTicketStatus $status): void
    {
        $this->counts[$status->value] = ($this->counts[$status->value] ?? 0) + 1;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
