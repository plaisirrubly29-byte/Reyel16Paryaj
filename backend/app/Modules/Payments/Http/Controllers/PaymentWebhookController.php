<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\WebhookOutcome;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks des prestataires de paiement (V2-B01). Sans compte ni session : la signature du prestataire fait foi.
 * Réponse 2xx dès que l'événement est enregistré, même sans effet (rejeu, transaction inconnue) : le prestataire
 * cesse alors de le renvoyer. 401 / 400 : le prestataire réessaiera, ce qui est voulu.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentProviderRegistry $providers, PaymentService $payments): JsonResponse
    {
        $paymentProvider = $providers->find($provider)
            ?? throw new ApiException('not_found', __('Prestataire inconnu.'), 404);

        $outcome = $payments->handleWebhook($paymentProvider, $request);

        return match ($outcome) {
            WebhookOutcome::InvalidSignature => throw new ApiException('invalid_signature', __('Signature invalide.'), 401),
            WebhookOutcome::InvalidPayload => throw new ApiException('invalid_payload', __('Contenu du webhook illisible.'), 400),
            default => response()->json(['data' => ['outcome' => $outcome->value]]),
        };
    }
}
