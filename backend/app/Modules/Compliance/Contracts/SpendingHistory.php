<?php

namespace App\Modules\Compliance\Contracts;

use App\Models\User;
use DateTimeInterface;

/** Mouvements d'argent d'un joueur jour par jour, fournis par le module Wallet (V2-E06). */
interface SpendingHistory
{
    /**
     * Totaux par jour du fuseau $timezone depuis $since, montants positifs en unité mineure.
     * Les jours sans mouvement sont absents.
     *
     * @return array<string, array{deposits: int, withdrawals: int, stakes: int, payouts: int, refunds: int}> clé : AAAA-MM-JJ
     */
    public function dailyTotals(User $user, DateTimeInterface $since, string $timezone): array;
}
