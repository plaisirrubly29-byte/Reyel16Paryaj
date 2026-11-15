<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Http\Resources\WithdrawalResource;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PayoutService;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Retraits du joueur connecté (contrat V2-B03, écran V2-B05) : la demande réserve le montant ; le versement part
 * ensuite automatiquement chez le prestataire (payments:payouts) vers le portefeuille MonCash ou NatCash indiqué.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $withdrawals = PaymentTransaction::where('user_id', $request->user()->id)
            ->where('direction', PaymentDirection::Payout)
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return WithdrawalResource::collection($withdrawals);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'method' => ['required', 'string', Rule::in(array_keys((array) config('payments.withdrawals.methods')))],
            // Numéro haïtien à 8 chiffres, comme le téléphone du compte.
            'recipient' => ['required', 'string', 'regex:/^[2-9][0-9]{7}$/'],
            'amount_minor' => ['required', 'integer', 'min:'.(int) config('payments.withdrawals.min_minor'), 'max:'.(int) config('payments.withdrawals.max_minor')],
        ]);

        $transaction = $this->payouts->request(
            $request->user(),
            $data['provider'],
            Money::ofMinor($data['amount_minor'], (string) config('wallet.currency')),
            $data['method'],
            '509'.$data['recipient'], // format attendu par Plop Plop
        );

        return (new WithdrawalResource($transaction))->response()->setStatusCode(201);
    }

    /** Suivi d'un retrait ; celui d'un autre joueur est introuvable (404). */
    public function show(Request $request, string $reference): WithdrawalResource
    {
        $transaction = PaymentTransaction::where('user_id', $request->user()->id)
            ->where('direction', PaymentDirection::Payout)
            ->where('reference', $reference)
            ->first()
            ?? throw new ApiException('not_found', __('Retrait introuvable.'), 404);

        return new WithdrawalResource($transaction);
    }

    public function cancel(Request $request, string $reference): WithdrawalResource
    {
        return new WithdrawalResource($this->payouts->cancel($request->user(), $reference));
    }
}
