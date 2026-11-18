<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Services\OddsOverrideService;
use Illuminate\Console\Command;

/**
 * Fin des cotes fixées à la main (V2-D03, D-18) arrivées à leur date limite, ou dont le match a commencé : la cote
 * calculée revient. Planifiée chaque minute (routes/console.php) ; idempotente.
 */
class ExpireOddsOverridesCommand extends Command
{
    protected $signature = 'odds:expire-overrides';

    protected $description = 'Met fin aux cotes fixées à la main arrivées à leur date limite ou au début du match';

    public function handle(OddsOverrideService $overrides): int
    {
        $count = $overrides->expireDue();
        $this->info("{$count} cote(s) fixée(s) à la main expirée(s).");

        return self::SUCCESS;
    }
}
