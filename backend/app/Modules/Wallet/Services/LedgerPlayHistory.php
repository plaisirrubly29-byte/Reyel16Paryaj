<?php

namespace App\Modules\Wallet\Services;

use App\Models\User;
use App\Modules\Compliance\Contracts\LossHistory;
use App\Modules\Compliance\Contracts\SpendingHistory;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/** Pertes et historique des dépenses d'un joueur, lus dans le ledger (V2-E06). */
class LedgerPlayHistory implements LossHistory, SpendingHistory
{
    /** Colonne de l'historique pour chaque type d'écriture compté. */
    private const COLUMNS = [
        'deposit' => 'deposits',
        'withdrawal' => 'withdrawals',
        'stake' => 'stakes',
        'payout' => 'payouts',
        'refund' => 'refunds',
    ];

    public function lostSince(User $user, DateTimeInterface $since): int
    {
        // Mises (négatives), gains et remboursements (positifs) : leur somme négative est la perte nette.
        $result = (int) $this->entries($user, $since)
            ->whereIn('type', [LedgerEntryType::Stake, LedgerEntryType::Payout, LedgerEntryType::Refund])
            ->sum('amount_minor');

        return max(0, -$result);
    }

    public function dailyTotals(User $user, DateTimeInterface $since, string $timezone): array
    {
        $days = [];
        $entries = $this->entries($user, $since)
            ->whereIn('type', array_keys(self::COLUMNS))
            ->toBase()
            ->select(['type', 'amount_minor', 'created_at'])
            ->cursor();

        foreach ($entries as $entry) {
            $day = CarbonImmutable::parse($entry->created_at, 'UTC')->setTimezone($timezone)->toDateString();
            $days[$day] ??= array_fill_keys(array_values(self::COLUMNS), 0);
            $days[$day][self::COLUMNS[$entry->type]] += abs((int) $entry->amount_minor);
        }

        return $days;
    }

    /** @return Builder<LedgerEntry> */
    private function entries(User $user, DateTimeInterface $since): Builder
    {
        return LedgerEntry::where('created_at', '>=', $since)
            ->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $user->id));
    }
}
