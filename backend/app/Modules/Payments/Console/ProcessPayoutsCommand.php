<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\PayoutService;
use Illuminate\Console\Command;

/**
 * Verse les retraits en attente (un à la fois, en respectant le délai du prestataire) et vérifie ceux dont le
 * résultat n'est pas encore connu (V2-B03). Sans effet si les retraits sont désactivés.
 */
class ProcessPayoutsCommand extends Command
{
    protected $signature = 'payments:payouts';

    protected $description = 'Verse les retraits en attente chez le prestataire et vérifie ceux en cours';

    public function handle(PayoutService $payouts): int
    {
        $this->info($payouts->run().' retrait(s) terminé(s).');

        return self::SUCCESS;
    }
}
