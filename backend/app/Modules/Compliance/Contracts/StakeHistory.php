<?php

namespace App\Modules\Compliance\Contracts;

use App\Models\User;
use DateTimeInterface;

/**
 * Montant misé par un joueur depuis une date, fourni par le module Betting.
 * Compliance ne lit pas les tickets elle-même : Betting dépend de Compliance, jamais l'inverse.
 */
interface StakeHistory
{
    /** Total des mises acceptées depuis $since, en unité mineure. */
    public function stakedSince(User $user, DateTimeInterface $since): int;
}
