<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Compliance\Exceptions\ResponsibleGamingException;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\WebhookOutcome;
use App\Modules\Payments\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Payments\Support\PaymentNotification;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Règles communes à tous les prestataires de paiement (V2-B01) :
 * - seul un webhook à la signature vérifiée a un effet financier ;
 * - un même événement (provider, event_id) n'a qu'un effet, même rejoué ou reçu deux fois en parallèle ;
 * - un dépôt n'est crédité qu'une fois (référence unique au ledger) et seulement si le montant et la devise
 *   correspondent ; sinon la transaction passe en revue, sans effet financier ;
 * - un succès reçu après l'expiration est crédité (l'argent a quitté le compte du joueur) et signalé ;
 * - chaque webhook reçu est journalisé (webhook_events), même refusé.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly LedgerService $ledger,
        private readonly ResponsibleGamingService $responsibleGaming,
    ) {}

    /**
     * Ouvre le dépôt d'un joueur chez le prestataire (POST /payments/deposits). Contrôles avant tout appel au
     * prestataire : compte joueur, portefeuille actif, limites de dépôt du joueur (V2-B06) en comptant ses dépôts
     * encore en attente. Rien n'est crédité ici : seul le webhook vérifié du prestataire crédite.
     *
     * @throws ApiException payment_provider_unavailable | not_a_player | wallet_frozen | payment_provider_error
     * @throws ResponsibleGamingException deposit_limit_exceeded (refus journalisé)
     */
    public function startDeposit(User $user, Money $amount, string $providerName): PaymentTransaction
    {
        $provider = $this->providers->find($providerName)
            ?? throw new ApiException('payment_provider_unavailable', __('Ce moyen de paiement n\'est pas disponible.'), 422);

        if ($user->isAdmin()) {
            throw new ApiException('not_a_player', __('Seul un joueur peut déposer.'), 422);
        }

        try {
            $transaction = DB::transaction(function () use ($user, $amount, $provider) {
                // Verrou sur le joueur : ses dépôts simultanés passent un par un devant les limites.
                User::whereKey($user->id)->lockForUpdate()->first();

                if ($this->ledger->walletFor($user, $amount->currency)->status !== Wallet::STATUS_ACTIVE) {
                    throw new ApiException('wallet_frozen', __('Ce portefeuille est gelé.'), 423);
                }

                $this->responsibleGaming->assertCanDeposit($user, $amount->minor, $this->pendingDepositsMinor($user));

                return PaymentTransaction::create([
                    'user_id' => $user->id,
                    'provider' => $provider->name(),
                    'direction' => PaymentDirection::Deposit,
                    'status' => PaymentStatus::Pending,
                    'amount_minor' => $amount->minor,
                    'currency' => $amount->currency,
                    'reference' => 'pay_'.Str::lower((string) Str::ulid()),
                    'expires_at' => now()->addMinutes((int) config('payments.deposit_expiry_minutes')),
                ]);
            });
        } catch (ResponsibleGamingException $refusal) {
            // Le dépôt n'est pas ouvert : le refus est journalisé hors de la transaction annulée.
            $this->responsibleGaming->recordDepositRefusal($user, $refusal, $amount->minor);

            throw $refusal;
        }

        // Appel au prestataire hors transaction : un prestataire lent ne garde pas le verrou du joueur.
        try {
            $initiation = $provider->initiate($transaction);
        } catch (Throwable $e) {
            $transaction->forceFill(['status' => PaymentStatus::Failed, 'completed_at' => now(), 'metadata' => ['error' => 'initiation_failed']])->save();
            Log::error('Ouverture du paiement refusée par le prestataire.', ['provider' => $provider->name(), 'reference' => $transaction->reference, 'exception' => $e]);

            throw new ApiException('payment_provider_error', __('Le prestataire de paiement ne répond pas. Réessayez plus tard.'), 502);
        }

        $transaction->forceFill([
            'provider_reference' => $initiation->providerReference,
            'metadata' => $initiation->redirectUrl === null ? null : ['redirect_url' => $initiation->redirectUrl],
        ])->save();

        return $transaction;
    }

    /** Traite un webhook et le journalise ; le résultat décide de la réponse HTTP. */
    public function handleWebhook(PaymentProvider $provider, Request $request): WebhookOutcome
    {
        if (! $provider->verifyWebhook($request)) {
            Log::warning('Webhook de paiement refusé : signature invalide.', ['provider' => $provider->name(), 'ip' => $request->ip()]);

            return $this->journal($provider, $request, null, null, false, WebhookOutcome::InvalidSignature);
        }

        try {
            $notification = $provider->parseWebhook($request);
        } catch (InvalidWebhookException) {
            return $this->journal($provider, $request, null, null, true, WebhookOutcome::InvalidPayload);
        }

        // Événement déjà traité : même réponse, aucun nouvel effet.
        $known = WebhookEvent::where('provider', $provider->name())->where('event_id', $notification->eventId)->first();
        if ($known !== null) {
            return $known->outcome;
        }

        try {
            return DB::transaction(function () use ($provider, $request, $notification) {
                // Verrou sur la transaction : deux webhooks d'un même paiement passent un par un.
                $transaction = PaymentTransaction::where('provider', $provider->name())
                    ->where('reference', $notification->reference)
                    ->lockForUpdate()
                    ->first();

                $outcome = $transaction === null ? WebhookOutcome::UnknownTransaction : $this->apply($transaction, $notification);

                return $this->journal($provider, $request, $notification->eventId, $transaction?->id, true, $outcome);
            });
        } catch (UniqueConstraintViolationException) {
            // Le même événement reçu deux fois en parallèle : le second est annulé avec sa transaction.
            return WebhookEvent::where('provider', $provider->name())->where('event_id', $notification->eventId)->sole()->outcome;
        }
    }

    /** Montant des dépôts du joueur ouverts et pas encore confirmés ni expirés. */
    private function pendingDepositsMinor(User $user): int
    {
        return (int) PaymentTransaction::where('user_id', $user->id)
            ->where('direction', PaymentDirection::Deposit)
            ->where('status', PaymentStatus::Pending)
            ->where('expires_at', '>', now())
            ->sum('amount_minor');
    }

    /** Dépôts jamais confirmés dans le délai : « expired » (commande payments:expire). */
    public function expirePending(): int
    {
        return PaymentTransaction::where('status', PaymentStatus::Pending)
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentStatus::Expired, 'updated_at' => now()]);
    }

    private function apply(PaymentTransaction $transaction, PaymentNotification $notification): WebhookOutcome
    {
        if ($transaction->status->isFinal() || $notification->status === PaymentStatus::Pending) {
            return WebhookOutcome::Ignored;
        }

        if ($notification->status === PaymentStatus::Failed) {
            $transaction->forceFill(['status' => PaymentStatus::Failed, 'completed_at' => now()])->save();

            return WebhookOutcome::Applied;
        }

        if ($notification->amountMinor !== $transaction->amount_minor || $notification->currency !== $transaction->currency) {
            $transaction->forceFill([
                'status' => PaymentStatus::Review,
                'review_reason' => 'amount_mismatch',
                'metadata' => ($transaction->metadata ?? []) + ['reported' => ['amount_minor' => $notification->amountMinor, 'currency' => $notification->currency]],
            ])->save();
            Log::warning('Paiement mis en revue : montant ou devise différents.', ['reference' => $transaction->reference]);

            return WebhookOutcome::Review;
        }

        $late = $transaction->status === PaymentStatus::Expired;

        // Dépôt : crédit unique (référence unique au ledger). Versement d'un retrait : le montant a déjà été débité
        // à la demande (V2-B03), le succès ne fait que le confirmer.
        $entry = $transaction->direction === PaymentDirection::Deposit
            ? $this->ledger->providerDeposit(
                $this->ledger->walletFor($transaction->user, $transaction->currency),
                Money::ofMinor($transaction->amount_minor, $transaction->currency),
                "payment:{$transaction->reference}",
                (string) $transaction->id,
                (string) config("payments.providers.{$transaction->provider}.label", $transaction->provider),
            )
            : null;

        $transaction->forceFill([
            'status' => PaymentStatus::Succeeded,
            'ledger_entry_id' => $entry?->id,
            'completed_at' => now(),
            'metadata' => $late ? ($transaction->metadata ?? []) + ['late' => true] : $transaction->metadata,
        ])->save();

        if ($late) {
            Log::warning('Paiement confirmé après son expiration : appliqué.', ['reference' => $transaction->reference]);
        }

        return $late ? WebhookOutcome::AppliedLate : WebhookOutcome::Applied;
    }

    private function journal(PaymentProvider $provider, Request $request, ?string $eventId, ?int $transactionId, bool $signatureValid, WebhookOutcome $outcome): WebhookOutcome
    {
        WebhookEvent::create([
            'provider' => $provider->name(),
            'event_id' => $eventId,
            'payment_transaction_id' => $transactionId,
            'signature_valid' => $signatureValid,
            'outcome' => $outcome,
            'payload' => mb_substr($request->getContent(), 0, 60_000),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return $outcome;
    }
}
