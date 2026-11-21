<?php

namespace App\Modules\Payments\Enums;

/** Sens d'une transaction : dépôt du joueur (V2-B02) ou versement d'un retrait (V2-B03). */
enum PaymentDirection: string
{
    case Deposit = 'deposit';
    case Payout = 'payout';
}
