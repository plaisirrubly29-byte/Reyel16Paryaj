<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\LimitKind;

/**
 * Limite de pertes nettes d'un joueur sur une période glissante (V2-E06) : mêmes colonnes et même règle
 * qu'une limite de mise, dans la table loss_limits.
 */
class LossLimit extends BettingLimit
{
    protected $table = 'loss_limits';

    public function kind(): LimitKind
    {
        return LimitKind::Loss;
    }
}
