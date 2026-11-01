<?php

namespace App\Modules\Wallet\Enums;

/**
 * Types d'écritures. Le sens (crédit / débit) est fixé par le type, jamais par l'appelant.
 */
enum LedgerEntryType: string
{
    /** Fonds fictifs attribués (V1 simulée). */
    case SimulatedCredit = 'simulated_credit';

    /** Dépôt enregistré par le Super Admin (paiement reçu hors plateforme). */
    case Deposit = 'deposit';

    /** Retrait enregistré par le Super Admin (montant remis au joueur hors plateforme). */
    case Withdrawal = 'withdrawal';

    /** Mise réservée à l'acceptation d'un ticket. */
    case Stake = 'stake';

    /** Gain versé au règlement d'un ticket gagnant. */
    case Payout = 'payout';

    /** Remboursement de mise (ticket annulé, match reporté…). */
    case Refund = 'refund';

    /** Écriture compensatoire d'une écriture existante (sens opposé). */
    case Reversal = 'reversal';

    /** +1 crédit, -1 débit ; null : déterminé par l'écriture compensée. */
    public function sign(): ?int
    {
        return match ($this) {
            self::SimulatedCredit, self::Deposit, self::Payout, self::Refund => 1,
            self::Stake, self::Withdrawal => -1,
            self::Reversal => null,
        };
    }
}
