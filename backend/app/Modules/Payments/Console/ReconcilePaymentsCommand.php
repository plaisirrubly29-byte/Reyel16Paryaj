<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Relit chez le prestataire le statut des dépôts encore en attente (ou expirés) des dernières heures : le webhook de
 * Plop Plop n'est envoyé qu'une fois, sans nouvel essai. Même règles qu'un webhook (crédit unique, contrôle du montant).
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--hours=24 : Dépôts ouverts depuis moins de ce nombre d\'heures}';

    protected $description = 'Vérifie chez le prestataire les dépôts en attente et applique leur statut';

    public function handle(PaymentService $payments): int
    {
        $this->info($payments->reconcilePending(max(1, (int) $this->option('hours'))).' paiement(s) mis à jour.');

        return self::SUCCESS;
    }
}
