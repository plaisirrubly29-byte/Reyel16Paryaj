<?php

namespace App\Modules\Compliance\Enums;

/** Règles AML (V2-C04). Seuils dans config/compliance.php (aml). */
enum AmlRule: string
{
    /** Un dépôt atteint à lui seul le seuil. */
    case LargeDeposit = 'large_deposit';

    /** Ce dépôt fait franchir le seuil au cumul des dépôts de la période. */
    case CumulativeDeposits = 'cumulative_deposits';

    /** Retrait peu après des dépôts presque pas joués. */
    case WithdrawalWithoutPlay = 'withdrawal_without_play';
}
