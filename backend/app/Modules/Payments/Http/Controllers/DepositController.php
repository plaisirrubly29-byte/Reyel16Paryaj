<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Http\Resources\DepositResource;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PayoutService;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * Dépôts du joueur connecté par un prestataire de paiement (V2-B02 / V2-B05). Ouvrir un dépôt ne crédite rien :
 * le joueur confirme chez le prestataire, puis seul le webhook vérifié crédite le solde (PaymentService).
 */
class DepositController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Moyens de paiement activés et montants acceptés : une entrée par moyen que le joueur choisit (Plop Plop :
     * MonCash, NatCash), method null pour un prestataire sans choix. Liste vide : aucun dépôt en ligne possible.
     */
    public function methods(PaymentProviderRegistry $providers, PayoutService $payouts): JsonResponse
    {
        $methods = collect(array_keys((array) config('payments.providers')))
            ->filter(fn (string $name) => $providers->find($name) !== null)
            ->flatMap(fn (string $name) => $providers->methods($name) === []
                ? [['provider' => $name, 'method' => null, 'label' => $providers->label($name)]]
                : collect($providers->methods($name))->map(fn (string $label, string $method) => ['provider' => $name, 'method' => $method, 'label' => $label])->values())
            ->values();

        $currency = (string) config('wallet.currency');

        return response()->json(['data' => [
            'methods' => $methods,
            'min_amount' => ['minor' => (int) config('payments.deposit_min_minor'), 'currency' => $currency],
            'max_amount' => ['minor' => (int) config('payments.deposit_max_minor'), 'currency' => $currency],
            // Retraits (contrat V2-B03) : methods vide quand aucun prestataire de versement n'est activé.
            'withdrawal' => [
                'methods' => ($payout = $payouts->provider()) === null ? [] : collect((array) config('payments.withdrawals.methods'))
                    ->map(fn (string $label, string $method) => ['provider' => $payout->name(), 'method' => $method, 'label' => $label])->values(),
                'min_amount' => ['minor' => (int) config('payments.withdrawals.min_minor'), 'currency' => $currency],
                'max_amount' => ['minor' => (int) config('payments.withdrawals.max_minor'), 'currency' => $currency],
            ],
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
            // Moyen choisi (GET /payments/methods) ; contrôlé par PaymentService selon le prestataire.
            'method' => ['nullable', 'string', 'max:40'],
            'amount_minor' => ['required', 'integer', 'min:'.(int) config('payments.deposit_min_minor'), 'max:'.(int) config('payments.deposit_max_minor')],
        ]);

        $transaction = $this->payments->startDeposit(
            $request->user(),
            Money::ofMinor($data['amount_minor'], (string) config('wallet.currency')),
            $data['provider'],
            $data['method'] ?? null,
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

        // Retour de la page du prestataire : si le webhook n'est pas encore arrivé, le statut est relu chez le
        // prestataire (au plus toutes les 10 secondes par dépôt), pour créditer le joueur sans attendre.
        if (in_array($transaction->status, [PaymentStatus::Pending, PaymentStatus::Expired], true)
            && Cache::add("payments:check:{$transaction->id}", true, 10)
            && $this->payments->reconcile($transaction) !== null) {
            $transaction->refresh();
        }

        return new DepositResource($transaction);
    }
}
