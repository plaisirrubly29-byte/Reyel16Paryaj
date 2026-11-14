<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Contracts\KycVerifier;
use App\Modules\Payments\Contracts\PayoutProvider;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PayoutResult;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retraits des joueurs (V2-B03), versés automatiquement par un prestataire (Plop Plop) depuis son portefeuille
 * marchand, sans validation manuelle (choix de Rubly du 2026-11-13, à la place de la double validation du contrat) :
 * 1. demande du joueur : contrôles, montant débité (réservé) au ledger, retrait « pending » (requested pour le joueur) ;
 * 2. versement (payments:payouts) : solde marchand vérifié, puis versement chez le prestataire, un à la fois et
 *    en respectant son délai entre deux versements ;
 * 3. résultat : versé → « succeeded » (paid) ; refusé → « failed » et montant rendu par une écriture reversal ;
 *    inconnu → « processing », vérifié ensuite par la référence. Tant qu'il n'est pas envoyé, le joueur peut annuler.
 * Un retrait n'est jamais versé deux fois : la référence est unique chez le prestataire, et un retrait au résultat
 * inconnu n'est renvoyé que si le prestataire confirme ne pas le connaître. Chaque étape est auditée.
 */
class PayoutService
{
    private const LAST_PAYOUT_KEY = 'payments:payout:last-at';

    /** Un retrait « processing » est vérifié chez le prestataire après ce délai. */
    private const CHECK_AFTER_SECONDS = 60;

    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
        private readonly KycVerifier $kyc,
    ) {}

    /** Prestataire qui verse, s'il est activé, configuré pour les retraits, et si les retraits sont ouverts. */
    public function provider(): ?PayoutProvider
    {
        if (! config('payments.withdrawals.enabled')) {
            return null;
        }

        $provider = $this->providers->find((string) config('payments.withdrawals.provider'));

        return $provider instanceof PayoutProvider ? $provider : null;
    }

    /**
     * @param  string  $recipient  numéro au format 509XXXXXXXX
     *
     * @throws ApiException payment_provider_unavailable | not_a_player | kyc_required | withdrawal_already_pending
     *                      | withdrawal_daily_limit_exceeded | insufficient_funds | wallet_frozen
     */
    public function request(User $user, string $providerName, Money $amount, string $method, string $recipient): PaymentTransaction
    {
        $provider = $this->provider();
        if ($provider === null || $provider->name() !== $providerName) {
            throw new ApiException('payment_provider_unavailable', __('Les retraits ne sont pas disponibles pour le moment.'), 422);
        }

        if ($user->isAdmin()) {
            throw new ApiException('not_a_player', __('Seul un joueur peut retirer.'), 422);
        }

        if (config('payments.withdrawals.require_kyc') && ! $this->kyc->isVerified($user)) {
            throw new ApiException('kyc_required', __('Faites vérifier votre identité avant votre premier retrait.'), 422);
        }

        return DB::transaction(function () use ($user, $amount, $method, $recipient, $provider) {
            // Verrou sur le joueur : ses demandes simultanées passent une par une.
            User::whereKey($user->id)->lockForUpdate()->first();

            // Un seul retrait en cours par joueur (contrat V2-B03).
            $current = PaymentTransaction::where('user_id', $user->id)
                ->where('direction', PaymentDirection::Payout)
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])
                ->first();
            if ($current !== null) {
                throw new ApiException('withdrawal_already_pending', __('Un retrait est déjà en cours.'), 409, ['reference' => $current->reference]);
            }

            $daily = (int) config('payments.withdrawals.daily_max_minor');
            $used = (int) PaymentTransaction::where('user_id', $user->id)
                ->where('direction', PaymentDirection::Payout)
                ->whereNotIn('status', [PaymentStatus::Failed, PaymentStatus::Cancelled])
                ->where('created_at', '>=', now()->subDay())
                ->sum('amount_minor');
            if ($used + $amount->minor > $daily) {
                throw new ApiException('withdrawal_daily_limit_exceeded', __('Ce retrait dépasse le plafond de retraits sur 24 heures.'), 422, [
                    'limit_minor' => $daily, 'remaining_minor' => max(0, $daily - $used),
                ]);
            }

            $transaction = PaymentTransaction::create([
                'user_id' => $user->id,
                'provider' => $provider->name(),
                'direction' => PaymentDirection::Payout,
                'status' => PaymentStatus::Pending,
                'amount_minor' => $amount->minor,
                'currency' => $amount->currency,
                'reference' => 'pay_'.Str::lower((string) Str::ulid()),
                'metadata' => ['method' => $method, 'recipient' => $recipient],
            ]);

            // Montant réservé tout de suite : refusé si le solde ne suffit pas (la transaction est alors annulée).
            $entry = $this->ledger->providerWithdrawal(
                $this->ledger->walletFor($user, $amount->currency),
                $amount,
                "payout:{$transaction->reference}",
                (string) $transaction->id,
                self::methodLabel($method),
            );
            $transaction->forceFill(['ledger_entry_id' => $entry->id])->save();

            $this->audit->record(AuditAction::WithdrawalRequested, $transaction,
                after: ['status' => 'requested', 'amount_minor' => $amount->minor, 'currency' => $amount->currency, 'method' => $method],
                reason: 'Retrait demandé par le joueur',
                actor: $user,
            );

            return $transaction;
        });
    }

    /**
     * Annulation par le joueur, tant que le retrait n'a pas été envoyé au prestataire : montant rendu.
     *
     * @throws ApiException not_found | withdrawal_not_cancellable
     */
    public function cancel(User $user, string $reference): PaymentTransaction
    {
        return DB::transaction(function () use ($user, $reference) {
            $transaction = PaymentTransaction::where('user_id', $user->id)
                ->where('direction', PaymentDirection::Payout)
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first()
                ?? throw new ApiException('not_found', __('Retrait introuvable.'), 404);

            if ($transaction->status !== PaymentStatus::Pending) {
                throw new ApiException('withdrawal_not_cancellable', __('Ce retrait est déjà en cours de versement ou terminé.'), 409, [
                    'status' => $transaction->status->value,
                ]);
            }

            $this->refund($transaction, 'withdrawal_cancelled');
            $transaction->forceFill(['status' => PaymentStatus::Cancelled, 'completed_at' => now()])->save();

            $this->audit->record(AuditAction::WithdrawalCancelled, $transaction,
                before: ['status' => 'requested'], after: ['status' => 'cancelled'],
                reason: 'Retrait annulé par le joueur avant son versement',
                actor: $user,
            );

            return $transaction;
        });
    }

    /**
     * Une passe de la commande payments:payouts : vérifie les retraits au résultat inconnu, puis verse les retraits en
     * attente, un à la fois, en respectant le délai du prestataire. Renvoie le nombre de retraits terminés.
     */
    public function run(int $max = 20): int
    {
        $provider = $this->provider();
        if ($provider === null) {
            return 0;
        }

        $done = $this->checkProcessing($provider);

        for ($i = 0; $i < $max; $i++) {
            $result = $this->payNext($provider);
            if ($result === null) {
                break;
            }
            if (in_array($result, [PayoutResult::PAID, PayoutResult::REJECTED], true)) {
                $done++;
            }
            if ($result === PayoutResult::RETRY_LATER || $provider->payoutCooldownSeconds() > 0) {
                break; // un seul versement par passe quand le prestataire impose un délai
            }
        }

        return $done;
    }

    /** Verse le plus ancien retrait en attente ; null s'il n'y a rien à verser maintenant. */
    private function payNext(PayoutProvider $provider): ?string
    {
        $last = Cache::get(self::LAST_PAYOUT_KEY);
        if ($last !== null && now()->getTimestamp() - (int) $last < $provider->payoutCooldownSeconds()) {
            return null;
        }

        // Le retrait passe en « processing » AVANT l'appel : un arrêt brutal pendant l'appel ne le fait jamais
        // renvoyer à l'aveugle, il sera vérifié par sa référence.
        $transaction = DB::transaction(function () use ($provider) {
            $next = PaymentTransaction::where('direction', PaymentDirection::Payout)
                ->where('provider', $provider->name())
                ->where('status', PaymentStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $next?->forceFill([
                'status' => PaymentStatus::Processing,
                'metadata' => array_merge($next->metadata ?? [], ['sent_at' => now()->toIso8601String()]),
            ])->save();

            return $next;
        });

        if ($transaction === null) {
            return null;
        }

        $available = $this->safely(fn () => $provider->availableBalance());
        if (is_int($available) && $available < $transaction->amount_minor) {
            Log::error('Retrait en attente : solde du portefeuille marchand insuffisant.', [
                'reference' => $transaction->reference, 'amount_minor' => $transaction->amount_minor, 'available_minor' => $available,
            ]);
            $this->backToQueue($transaction, 'merchant_balance_insufficient');

            return PayoutResult::RETRY_LATER;
        }

        Cache::put(self::LAST_PAYOUT_KEY, now()->getTimestamp(), now()->addHour());
        $result = $this->safely(fn () => $provider->payout($transaction)) ?? PayoutResult::unknown('Exception pendant le versement.');
        $this->settle($transaction, $result);

        return $result->outcome;
    }

    /** Retraits envoyés au résultat inconnu : relus chez le prestataire par leur référence. */
    private function checkProcessing(PayoutProvider $provider): int
    {
        $done = 0;

        PaymentTransaction::where('direction', PaymentDirection::Payout)
            ->where('provider', $provider->name())
            ->where('status', PaymentStatus::Processing)
            ->where('updated_at', '<=', now()->subSeconds(self::CHECK_AFTER_SECONDS))
            ->orderBy('id')
            ->each(function (PaymentTransaction $transaction) use ($provider, &$done) {
                $result = $this->safely(fn () => $provider->checkPayout($transaction), false);
                if ($result === false) {
                    return; // prestataire injoignable : nouvel essai à la prochaine passe
                }

                if ($result === null) {
                    // Inconnu du prestataire : jamais exécuté, il peut repartir sans risque de double versement.
                    $this->backToQueue($transaction, 'not_found_at_provider');

                    return;
                }

                if (in_array($result->outcome, [PayoutResult::PAID, PayoutResult::REJECTED], true)) {
                    $this->settle($transaction, $result);
                    $done++;
                } else {
                    $transaction->touch(); // toujours en cours : prochaine vérification dans une minute
                }
            });

        return $done;
    }

    private function settle(PaymentTransaction $transaction, PayoutResult $result): void
    {
        DB::transaction(function () use ($transaction, $result) {
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isFinal()) {
                return;
            }

            // Identifiants du versement dans metadata, pas dans provider_reference : chez Plop Plop, ils ont le même format
            // que ceux des dépôts, et provider_reference est unique par prestataire.
            $metadata = array_merge($locked->metadata ?? [], array_filter([
                'provider_transaction_id' => $result->providerReference,
                'external_reference' => $result->externalReference,
                'fee_minor' => $result->feeMinor,
                'provider_message' => $result->message,
            ], fn ($value) => $value !== null));

            match ($result->outcome) {
                PayoutResult::PAID => $this->markPaid($locked, $metadata),
                PayoutResult::REJECTED => $this->reject($locked, $metadata, (string) $result->message),
                PayoutResult::RETRY_LATER => $this->backToQueue($locked, $result->message ?? 'retry_later'),
                default => $locked->forceFill(['metadata' => $metadata])->save(), // inconnu : reste « processing »
            };
        });

        Log::info('Retrait traité.', ['reference' => $transaction->reference, 'outcome' => $result->outcome, 'message' => $result->message]);
    }

    /** @param  array<string, mixed>  $metadata */
    private function markPaid(PaymentTransaction $transaction, array $metadata): void
    {
        $transaction->forceFill(['status' => PaymentStatus::Succeeded, 'completed_at' => now(), 'metadata' => $metadata])->save();

        $this->audit->record(AuditAction::WithdrawalPaid, $transaction,
            before: ['status' => 'processing'],
            after: ['status' => 'paid', 'amount_minor' => $transaction->amount_minor, 'fee_minor' => $metadata['fee_minor'] ?? null],
            reason: 'Versé par le prestataire',
            metadata: ['user_id' => $transaction->user_id, 'provider' => $transaction->provider],
        );
    }

    /** @param  array<string, mixed>  $metadata */
    private function reject(PaymentTransaction $transaction, array $metadata, string $message): void
    {
        $this->refund($transaction, 'withdrawal_failed');
        $transaction->forceFill(['status' => PaymentStatus::Failed, 'completed_at' => now(), 'metadata' => $metadata])->save();

        $this->audit->record(AuditAction::WithdrawalFailed, $transaction,
            before: ['status' => 'processing'], after: ['status' => 'failed'],
            reason: $message !== '' ? $message : 'Refusé par le prestataire',
            metadata: ['user_id' => $transaction->user_id, 'provider' => $transaction->provider],
        );
    }

    /** Montant réservé rendu par une écriture reversal de l'écriture withdrawal (contrat V2-B03), une seule fois. */
    private function refund(PaymentTransaction $transaction, string $reason): void
    {
        $this->ledger->reverse(
            LedgerEntry::findOrFail($transaction->ledger_entry_id),
            "payout:{$transaction->reference}:reversal",
            $reason,
        );
    }

    private function backToQueue(PaymentTransaction $transaction, string $reason): void
    {
        $transaction->forceFill([
            'status' => PaymentStatus::Pending,
            'metadata' => array_merge($transaction->metadata ?? [], ['last_retry_reason' => $reason]),
        ])->save();
    }

    /**
     * Appel au prestataire sans jamais casser la passe : l'exception est journalisée et $onError renvoyé.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|mixed
     */
    private function safely(callable $call, mixed $onError = null): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Log::warning('Appel au prestataire de retrait en échec.', ['exception' => $e->getMessage()]);

            return $onError;
        }
    }

    public static function methodLabel(string $method): string
    {
        return (string) (config("payments.withdrawals.methods.{$method}") ?? $method);
    }
}
