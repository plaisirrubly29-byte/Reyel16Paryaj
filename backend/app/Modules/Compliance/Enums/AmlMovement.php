<?php

namespace App\Modules\Compliance\Enums;

/** Mouvement d'argent contrôlé par les règles AML, signalé par le ledger. */
enum AmlMovement: string
{
    case Deposit = 'deposit';

    case Withdrawal = 'withdrawal';
}
