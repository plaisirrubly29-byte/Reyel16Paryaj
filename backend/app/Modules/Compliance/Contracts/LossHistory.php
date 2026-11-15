<?php

namespace App\Modules\Compliance\Contracts;

use App\Models\User;
use DateTimeInterface;

/**
 * Perte nette d'un joueur depuis une date, fournie par le module Wallet (V2-E06).
 * Compliance ne lit pas le ledger elle-même.
 */
interface LossHistory
{
    /** Mises moins gains et remboursements depuis $since, en unité mineure ; 0 si le joueur est gagnant. */
    public function lostSince(User $user, DateTimeInterface $since): int;
}
