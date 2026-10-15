<?php

namespace App\Modules\Wallet\Services;

use App\Models\User;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Point d'entrée UNIQUE de toute écriture financière (règle d'architecture n°4).
 *
 * Garanties :
 * - le solde est la somme des écritures ; chaque écriture mémorise le solde qui en résulte ;
 * - la ligne du portefeuille est verrouillée pendant l'écriture (pas de découvert par concurrence) ;
 * - une référence métier n'est jamais écrite deux fois : rejouer une opération renvoie l'écriture existante ;
 * - aucune écriture n'est modifiée : une correction est une écriture compensatoire (reverse).
 *
 * Les autres modules (Betting, Settlement…) appellent ce service, jamais les tables du ledger.
 */
class LedgerService
{
    public function walletFor(User $user, ?string $currency = null): Wallet
    {
        $currency ??= config('wallet.currency');

        return Wallet::firstOrCreate(['user_id' => $user->id, 'currency' => $currency]);
    }

    public function balance(Wallet $wallet): Money
    {
        return Money::ofMinor((int) $wallet->entries()->sum('amount_minor'), $wallet->currency);
    }

    /** Fonds fictifs (V1). Jamais exposé par une route joueur. */
    public function creditSimulated(Wallet $wallet, Money $amount, string $reference, ?string $description = null, ?User $actor = null): LedgerEntry
    {
        if (! config('wallet.simulated')) {
            throw new LogicException('Crédit simulé interdit hors mode simulé.');
        }

        return $this->post($wallet, LedgerEntryType::SimulatedCredit, $amount, $reference, description: $description, actor: $actor);
    }

    /**
     * Réserve (débite) la mise d'un ticket. Refusé si le solde disponible est insuffisant.
     *
     * @throws ApiException insufficient_funds
     */
    public function reserveStake(Wallet $wallet, Money $stake, string $reference, ?string $betId = null): LedgerEntry
    {
        return $this->post($wallet, LedgerEntryType::Stake, $stake, $reference, 'bet', $betId);
    }

    public function payout(Wallet $wallet, Money $amount, string $reference, ?string $betId = null): LedgerEntry
    {
        return $this->post($wallet, LedgerEntryType::Payout, $amount, $reference, 'bet', $betId);
    }

    public function refund(Wallet $wallet, Money $amount, string $reference, ?string $betId = null): LedgerEntry
    {
        return $this->post($wallet, LedgerEntryType::Refund, $amount, $reference, 'bet', $betId);
    }

    /**
     * Écriture compensatoire : annule l'effet de $entry par une écriture de sens opposé.
     * Une écriture ne peut être compensée qu'une fois ; une compensation ne se compense pas.
     */
    public function reverse(LedgerEntry $entry, string $reference, string $reason, ?User $actor = null): LedgerEntry
    {
        if ($entry->type === LedgerEntryType::Reversal) {
            throw new LogicException('Une écriture compensatoire ne peut pas être compensée.');
        }

        $existing = LedgerEntry::where('reversal_of_id', $entry->id)->first();
        if ($existing !== null) {
            return $this->assertReplay($existing, LedgerEntryType::Reversal, -$entry->amount_minor, $reference);
        }

        return $this->post(
            $entry->wallet,
            LedgerEntryType::Reversal,
            $entry->amount()->negate(),
            $reference,
            $entry->related_type,
            $entry->related_id,
            description: $reason,
            actor: $actor,
            reversalOf: $entry,
        );
    }

    /**
     * @param  Money  $amount  montant positif (sauf compensation : déjà signé)
     */
    private function post(
        Wallet $wallet,
        LedgerEntryType $type,
        Money $amount,
        string $reference,
        ?string $relatedType = null,
        ?string $relatedId = null,
        ?string $description = null,
        ?User $actor = null,
        ?LedgerEntry $reversalOf = null,
    ): LedgerEntry {
        if ($amount->currency !== $wallet->currency) {
            throw new InvalidArgumentException("Devise {$amount->currency} différente de celle du portefeuille ({$wallet->currency}).");
        }

        $sign = $type->sign();
        if ($sign !== null && $amount->minor <= 0) {
            throw new InvalidArgumentException('Le montant doit être strictement positif.');
        }

        $signed = $sign === null ? $amount->minor : $sign * $amount->minor;

        return DB::transaction(function () use ($wallet, $type, $signed, $reference, $relatedType, $relatedId, $description, $actor, $reversalOf) {
            // Verrou sur le portefeuille : les écritures d'un même portefeuille sont sérialisées.
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            // Idempotence : la même opération rejouée renvoie l'écriture d'origine.
            $existing = LedgerEntry::where('reference', $reference)->first();
            if ($existing !== null) {
                return $this->assertReplay($existing, $type, $signed, $reference, $locked->id);
            }

            if ($locked->status !== Wallet::STATUS_ACTIVE && $signed < 0) {
                throw new ApiException('wallet_frozen', __('Ce portefeuille est gelé.'), 423);
            }

            $balance = (int) LedgerEntry::where('wallet_id', $locked->id)->sum('amount_minor');
            $newBalance = $balance + $signed;

            if ($signed < 0 && $newBalance < 0 && $type !== LedgerEntryType::Reversal) {
                throw new ApiException('insufficient_funds', __('Solde insuffisant.'), 422, [
                    'balance' => ['minor' => $balance, 'currency' => $locked->currency],
                ]);
            }

            return LedgerEntry::create([
                'wallet_id' => $locked->id,
                'type' => $type,
                'amount_minor' => $signed,
                'currency' => $locked->currency,
                'balance_after_minor' => $newBalance,
                'reference' => $reference,
                'reversal_of_id' => $reversalOf?->id,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'description' => $description,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /** Une référence rejouée doit décrire exactement la même opération, sinon c'est une erreur de programmation. */
    private function assertReplay(LedgerEntry $existing, LedgerEntryType $type, int $signed, string $reference, ?int $walletId = null): LedgerEntry
    {
        if ($existing->type !== $type || $existing->amount_minor !== $signed || ($walletId !== null && $existing->wallet_id !== $walletId)) {
            throw new LogicException("La référence {$reference} est déjà utilisée pour une autre écriture.");
        }

        return $existing;
    }
}
