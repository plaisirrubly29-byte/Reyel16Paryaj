<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PayoutResult;

/**
 * Prestataire qui verse les retraits des joueurs (V2-B03) depuis son portefeuille marchand (ex. Plop Plop vers MonCash
 * ou NatCash). PayoutService réserve le montant au ledger à la demande, puis appelle le prestataire.
 */
interface PayoutProvider extends PaymentProvider
{
    /** Solde marchand disponible pour les retraits, en unité mineure ; null s'il n'est pas connu. */
    public function availableBalance(): ?int;

    /** Verse le retrait (méthode et destinataire dans metadata). Ne lève pas d'exception : tout est classé. */
    public function payout(PaymentTransaction $transaction): PayoutResult;

    /** Statut d'un retrait déjà envoyé, par sa référence ; null si le prestataire ne le connaît pas (jamais exécuté). */
    public function checkPayout(PaymentTransaction $transaction): ?PayoutResult;

    /** Délai minimal entre deux versements (ex. 120 s par IP chez Plop Plop). */
    public function payoutCooldownSeconds(): int;
}
