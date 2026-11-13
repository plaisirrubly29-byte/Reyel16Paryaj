<?php

namespace App\Modules\Compliance\Contracts;

use App\Models\User;
use DateTimeInterface;

/**
 * Montant déposé par un joueur depuis une date, fourni par le module Wallet (V2-B06).
 * Compliance ne lit pas le ledger elle-même : Wallet et Admin dépendent de Compliance, jamais l'inverse.
 */
interface DepositHistory
{
    /** Total des dépôts depuis $since, en unité mineure. */
    public function depositedSince(User $user, DateTimeInterface $since): int;
}
