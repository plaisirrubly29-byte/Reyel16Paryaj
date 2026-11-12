<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\WebhookOutcome;
use App\Modules\Payments\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Payments\Support\PaymentNotification;
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
    ) {}

    /**
     * Ouvre un dépôt chez le prestataire. V2-B02 l'exposera au joueur, après les contrôles KYC et limites de dépôt.
     *
     * @throws ApiException payment_provider_unavailable | payment_provider_error
     */
    public function startDeposit(User $user, Money $amount, string $providerName): PaymentTransaction
    {
        $provider = $this->providers->find($providerName)
            ?? throw new ApiException('payment_provider_unavailable', __('Ce moyen de paiement n\'est pas disponible.'), 422);

        $transaction = PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => $provider->name(),
            'direction' => PaymentDirection::Deposit,
            'status' => PaymentStatus::Pending,
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency,
            'reference' => 'pay_'.Str::lower((string) Str::ulid()),
            'expires_at' => now()->addMinutes((int) config('payments.deposit_expiry_minutes')),
        ]);

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
