<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Compliance\Models\AmlAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alerte AML vue par la Conformité : règle, mouvement en cause, constats et décision (V2-C04).
 * Charger user et reviewer pour éviter une requête par alerte.
 *
 * @mixin AmlAlert
 */
class AmlAlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rule' => $this->rule->value,
            'status' => $this->status->value,
            'user' => ['id' => (string) $this->user_id, 'name' => $this->user?->name],
            'ledger_entry_id' => (string) $this->ledger_entry_id,
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'details' => $this->details,
            'reviewed_by' => $this->reviewed_by === null ? null : ['id' => (string) $this->reviewed_by, 'name' => $this->reviewer?->name],
            'review_reason' => $this->review_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
