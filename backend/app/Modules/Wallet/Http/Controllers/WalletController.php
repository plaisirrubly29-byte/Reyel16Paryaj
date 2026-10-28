<?php

namespace App\Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Http\Resources\LedgerEntryResource;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Lecture du portefeuille de l'utilisateur connecté uniquement (aucun identifiant dans l'URL).
 * Aucune route ne modifie le solde : les écritures viennent des services (paris, règlement).
 */
class WalletController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->ledger->walletFor($request->user());
        $balance = $this->ledger->balance($wallet);

        return response()->json(['data' => [
            'currency' => $wallet->currency,
            'balance' => ['minor' => $balance->minor, 'currency' => $balance->currency],
            'status' => $wallet->status,
            'simulated' => (bool) config('wallet.simulated'),
        ]]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(LedgerEntryType::class)],
        ]);

        $wallet = $this->ledger->walletFor($request->user());

        $entries = $wallet->entries()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return LedgerEntryResource::collection($entries);
    }
}
