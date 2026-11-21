<?php

namespace App\Modules\Wallet\Services;

use App\Models\User;
use App\Modules\Compliance\Contracts\DepositHistory;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use DateTimeInterface;

/** Dépôts d'un joueur inscrits au ledger, pour les limites de dépôt (V2-B06). */
class LedgerDepositHistory implements DepositHistory
{
    public function depositedSince(User $user, DateTimeInterface $since): int
    {
        // Montant déposé, même s'il a été corrigé ensuite : c'est ce que la limite borne (comme les mises).
        return (int) LedgerEntry::where('type', LedgerEntryType::Deposit)
            ->where('created_at', '>=', $since)
            ->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $user->id))
            ->sum('amount_minor');
    }
}
