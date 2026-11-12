<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Http\Resources\DepositResource;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dépôts du joueur connecté par un prestataire de paiement (V2-B02 / V2-B05). Ouvrir un dépôt ne crédite rien :
 * le joueur confirme chez le prestataire, puis seul le webhook vérifié crédite le solde (PaymentService).
 */
class DepositController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Moyens de paiement activés et montants acceptés. Liste vide : aucun dépôt en ligne possible. */
    public function methods(PaymentProviderRegistry $providers): JsonResponse
    {
        $methods = collect(array_keys((array) config('payments.providers')))
            ->filter(fn (string $name) => $providers->find($name) !== null)
            ->map(fn (string $name) => ['provider' => $name, 'label' => (string) config("payments.providers.{$name}.label", $name)])
            ->values();

        $currency = (string) config('wallet.currency');

        return response()->json(['data' => [
            'methods' => $methods,
            'min_amount' => ['minor' => (int) config('payments.deposit_min_minor'), 'currency' => $currency],
            'max_amount' => ['minor' => (int) config('payments.deposit_max_minor'), 'currency' => $currency],
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $deposits = PaymentTransaction::where('user_id', $request->user()->id)
            ->where('direction', PaymentDirection::Deposit)
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return DepositResource::collection($deposits);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'amount_minor' => ['required', 'integer', 'min:'.(int) config('payments.deposit_min_minor'), 'max:'.(int) config('payments.deposit_max_minor')],
        ]);

        $transaction = $this->payments->startDeposit(
            $request->user(),
            Money::ofMinor($data['amount_minor'], (string) config('wallet.currency')),
            $data['provider'],
        );

        return (new DepositResource($transaction))->response()->setStatusCode(201);
    }

    /** Suivi d'un dépôt par sa référence ; le dépôt d'un autre joueur est introuvable (404). */
    public function show(Request $request, string $reference): DepositResource
    {
        $transaction = PaymentTransaction::where('user_id', $request->user()->id)
            ->where('direction', PaymentDirection::Deposit)
            ->where('reference', $reference)
            ->first()
            ?? throw new ApiException('not_found', __('Dépôt introuvable.'), 404);

        return new DepositResource($transaction);
    }
}
