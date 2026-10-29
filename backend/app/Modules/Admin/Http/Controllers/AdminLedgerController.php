<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminLedgerEntryResource;
use App\Modules\Admin\Services\ReconciliationService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminLedgerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'wallet_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::enum(LedgerEntryType::class)],
            'reference' => ['nullable', 'string', 'max:150'],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $entries = LedgerEntry::query()
            ->with(['wallet:id,user_id', 'reversedBy:id,reversal_of_id'])
            ->when($filters['user_id'] ?? null, fn ($q, int $userId) => $q->whereHas('wallet', fn ($w) => $w->where('user_id', $userId)))
            ->when($filters['wallet_id'] ?? null, fn ($q, int $walletId) => $q->where('wallet_id', $walletId))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['reference'] ?? null, fn ($q, string $reference) => $q->where('reference', $reference))
            ->when($filters['ticket_id'] ?? null, fn ($q, int $ticketId) => $q->where('related_type', 'bet')->where('related_id', (string) $ticketId))
            ->when($filters['from'] ?? null, fn ($q, string $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, string $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminLedgerEntryResource::collection($entries);
    }

    public function reconciliation(ReconciliationService $reconciliation): JsonResponse
    {
        return response()->json(['data' => $reconciliation->run()]);
    }
}
