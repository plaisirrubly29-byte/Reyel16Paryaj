<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminPaymentTransactionResource;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Journal des transactions des prestataires de paiement, en lecture seule (V2-B01, permission ledger.view). */
class AdminPaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'provider' => ['nullable', 'string', 'max:30'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $transactions = PaymentTransaction::query()
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['direction'] ?? null, fn ($q, string $direction) => $q->where('direction', $direction))
            ->when($filters['provider'] ?? null, fn ($q, string $provider) => $q->where('provider', $provider))
            ->when($filters['user_id'] ?? null, fn ($q, int $userId) => $q->where('user_id', $userId))
            // Notre référence ou celle du prestataire, telle que le joueur la donne au support.
            ->when($filters['reference'] ?? null, fn ($q, string $reference) => $q->where(fn ($q) => $q->where('reference', $reference)->orWhere('provider_reference', $reference)))
            ->when($filters['from'] ?? null, fn ($q, string $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, string $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminPaymentTransactionResource::collection($transactions);
    }

    public function show(PaymentTransaction $payment): AdminPaymentTransactionResource
    {
        return new AdminPaymentTransactionResource($payment->load(['webhookEvents' => fn ($q) => $q->orderBy('id')]));
    }
}
