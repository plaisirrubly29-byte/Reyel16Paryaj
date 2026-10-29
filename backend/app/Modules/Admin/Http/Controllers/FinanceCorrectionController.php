<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Http\Resources\FinanceCorrectionResource;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Admin\Services\FinanceCorrectionService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FinanceCorrectionController extends Controller
{
    private const RELATIONS = ['ledgerEntry.wallet:id,user_id', 'ledgerEntry.reversedBy:id,reversal_of_id', 'requester:id,name', 'decider:id,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(FinanceCorrectionStatus::class)],
        ]);

        $corrections = FinanceCorrection::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return FinanceCorrectionResource::collection($corrections);
    }

    public function store(Request $request, FinanceCorrectionService $corrections): JsonResponse
    {
        $data = $request->validate([
            'ledger_entry_id' => ['required', 'integer', 'exists:ledger_entries,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $correction = $corrections->request($request->user(), LedgerEntry::findOrFail($data['ledger_entry_id']), $data['reason']);

        return (new FinanceCorrectionResource($correction->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, FinanceCorrection $correction, FinanceCorrectionService $corrections): FinanceCorrectionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new FinanceCorrectionResource($corrections->approve($request->user(), $correction, $data['reason'])->load(self::RELATIONS));
    }

    public function reject(Request $request, FinanceCorrection $correction, FinanceCorrectionService $corrections): FinanceCorrectionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new FinanceCorrectionResource($corrections->reject($request->user(), $correction, $data['reason'])->load(self::RELATIONS));
    }
}
