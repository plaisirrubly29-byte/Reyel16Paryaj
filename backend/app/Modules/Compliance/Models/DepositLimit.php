<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\LimitKind;

/**
 * Limite de dépôt d'un joueur sur une période glissante (V2-B06) : mêmes colonnes et même règle
 * qu'une limite de mise, dans la table deposit_limits.
 */
class DepositLimit extends BettingLimit
{
    protected $table = 'deposit_limits';

    public function kind(): LimitKind
    {
        return LimitKind::Deposit;
    }
}
