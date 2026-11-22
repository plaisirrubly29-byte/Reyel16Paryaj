<?php

namespace App\Modules\Betting\Console;

use App\Modules\Betting\Services\LiveAcceptanceService;
use Illuminate\Console\Command;

/**
 * Paris en direct dont le délai d'acceptation est écoulé : acceptés ou refusés (mise rendue). Planifiée toutes les
 * 5 secondes, en filet de sécurité de la tâche en file ProcessLiveTicket.
 */
class LiveAcceptanceCommand extends Command
{
    protected $signature = 'betting:live-acceptance';

    protected $description = 'Accepte ou refuse les paris en direct dont le délai d\'acceptation est écoulé';

    public function handle(LiveAcceptanceService $acceptance): int
    {
        $count = $acceptance->processDue();
        if ($count > 0) {
            $this->info("{$count} pari(s) en direct traité(s).");
        }

        return self::SUCCESS;
    }
}
