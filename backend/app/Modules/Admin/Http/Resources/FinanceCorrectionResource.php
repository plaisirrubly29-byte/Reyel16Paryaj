<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Admin\Models\FinanceCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Correction financière et son circuit de validation (demandeur, second administrateur, écriture compensatoire).
 *
 * @mixin FinanceCorrection
 */
class FinanceCorrectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'ledger_entry' => new AdminLedgerEntryResource($this->whenLoaded('ledgerEntry')),
            'requested_by' => ['id' => (string) $this->requested_by, 'name' => $this->requester?->name],
            'requested_at' => $this->created_at->toIso8601String(),
            'decided_by' => $this->decided_by === null ? null : ['id' => (string) $this->decided_by, 'name' => $this->decider?->name],
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'reversal_entry_id' => $this->reversal_entry_id === null ? null : (string) $this->reversal_entry_id,
        ];
    }
}
