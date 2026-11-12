<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentStatusCheck;

/**
 * Prestataire dont on peut relire le statut d'un paiement (ex. Plop Plop : api/paiement-verify). Sert quand le
 * webhook n'arrive pas : il n'est envoyé qu'une fois, sans nouvel essai. Le résultat suit les mêmes règles qu'un
 * webhook (PaymentService::reconcile) : idempotence, contrôle du montant, crédit unique.
 */
interface ChecksPaymentStatus
{
    /** Statut actuel chez le prestataire, ou null s'il ne connaît pas (encore) ce paiement. */
    public function checkStatus(PaymentTransaction $transaction): ?PaymentStatusCheck;
}
