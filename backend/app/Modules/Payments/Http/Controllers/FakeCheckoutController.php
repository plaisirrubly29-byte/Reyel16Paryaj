<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\FakePaymentProvider;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Http\ApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Page de paiement du prestataire simulé (développement, Staging) : elle joue le rôle de la page MonCash où le
 * joueur confirme. Sa décision part comme un vrai webhook signé, traité par PaymentService (signature, idempotence,
 * crédit unique) ; rien n'est crédité directement. N'existe que si le faux prestataire est activé (jamais en
 * production). Remplacer le simulateur = activer un vrai prestataire : cette page n'est alors plus utilisée.
 */
class FakeCheckoutController extends Controller
{
    public function __construct(private readonly PaymentProviderRegistry $providers) {}

    public function show(string $reference): Response
    {
        $transaction = $this->transaction($reference);
        $nonce = Str::random(24);

        return response()
            ->view('payments.fake-checkout', [
                'transaction' => $transaction,
                'label' => (string) config('payments.providers.fake.label'),
                'amount' => Number::format($transaction->amount_minor / 100, 2, locale: 'fr').' '.$transaction->currency,
                'action' => '/api/v1/payments/fake/checkout/'.$transaction->reference,
                'returnUrl' => self::returnUrl($transaction),
                'statusText' => match ($transaction->status) {
                    PaymentStatus::Succeeded => 'Ce paiement a déjà été effectué.',
                    PaymentStatus::Failed => 'Ce paiement a été refusé.',
                    PaymentStatus::Expired => 'Ce paiement a expiré.',
                    default => 'Ce paiement est en cours de vérification.',
                },
                'nonce' => $nonce,
            ])
            // CSP propre à cette page (SecurityHeaders la garde) : son style en ligne, son formulaire, rien d'autre.
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
            ->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, string $reference, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,decline']]);
        $transaction = $this->transaction($reference);
        $provider = $this->providers->find('fake');

        if ($transaction->status === PaymentStatus::Pending) {
            $payments->handleWebhook($provider, FakePaymentProvider::simulatedWebhook(
                $transaction,
                $data['decision'] === 'approve' ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                (string) $request->ip(),
            ));
        }

        // Retour sur le site, comme après la page d'un prestataire ; le frontend y suit le statut du dépôt.
        return new RedirectResponse(self::returnUrl($transaction), 303);
    }

    private function transaction(string $reference): PaymentTransaction
    {
        if ($this->providers->find('fake') === null) {
            throw new ApiException('not_found', __('Page introuvable.'), 404);
        }

        return PaymentTransaction::where('provider', 'fake')->where('reference', $reference)->first()
            ?? throw new ApiException('not_found', __('Paiement introuvable.'), 404);
    }

    private static function returnUrl(PaymentTransaction $transaction): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/wallet?depot='.rawurlencode($transaction->reference);
    }
}
