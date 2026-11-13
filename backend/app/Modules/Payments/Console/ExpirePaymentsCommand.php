<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;

/** Dépôts jamais confirmés dans le délai (PAYMENTS_DEPOSIT_EXPIRY_MINUTES) : passés en « expired » (V2-B01). */
class ExpirePaymentsCommand extends Command
{
    protected $signature = 'payments:expire';

    protected $description = 'Passe en « expired » les dépôts restés en attente au-delà du délai';

    public function handle(PaymentService $payments): int
    {
        $this->info($payments->expirePending().' transaction(s) expirée(s).');

        return self::SUCCESS;
    }
}
