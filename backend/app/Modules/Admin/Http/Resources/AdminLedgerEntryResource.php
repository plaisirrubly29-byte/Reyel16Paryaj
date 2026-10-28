<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Admin\Services\FinanceCorrectionService;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Écriture du ledger vue par l'administration : référence, compensation, acteur et possibilité de correction.
 * Charger wallet et reversedBy pour éviter une requête par écriture.
 *
 * @mixin LedgerEntry
 */
class AdminLedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'wallet_id' => (string) $this->wallet_id,
            'user_id' => (string) $this->wallet->user_id,
            'type' => $this->type->value,
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'balance_after' => ['minor' => $this->balance_after_minor, 'currency' => $this->currency],
            'reference' => $this->reference,
            'related' => $this->related_type === null ? null : ['type' => $this->related_type, 'id' => $this->related_id],
            'reversal_of_id' => $this->reversal_of_id === null ? null : (string) $this->reversal_of_id,
            'reversed_by_id' => $this->reversedBy === null ? null : (string) $this->reversedBy->id,
            'description' => $this->description,
            'created_by' => $this->created_by === null ? null : (string) $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
            // Peut faire l'objet d'une correction financière (sinon : compensation, écriture d'un ticket ou déjà compensée).
            'correctable' => FinanceCorrectionService::blocker($this->resource) === null,
        ];
    }
}
