<?php

namespace App\Modules\Lottery\Console;

use App\Modules\Lottery\Services\LotterySettlementService;
use Illuminate\Console\Command;

/**
 * Règle les fiches de borlette dont le résultat est publié (gains crédités) et rembourse celles restées sans résultat.
 * Planifiée après chaque synchro (LOTTERY_SYNC_CRON) quand LOTTERY_ENABLED=true ; idempotente.
 */
class SettleLotteryCommand extends Command
{
    protected $signature = 'lottery:settle';

    protected $description = 'Règle les fiches de borlette dont le tirage a un résultat publié';

    public function handle(LotterySettlementService $settlement): int
    {
        $report = $settlement->settleDue();

        $this->info(sprintf('Fiches réglées : %d (gagnées %d, perdues %d, remboursées %d).',
            $report->total(), $report->counts['won'], $report->counts['lost'], $report->counts['void']));

        return self::SUCCESS;
    }
}
