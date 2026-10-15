<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Écriture vue par le joueur (pas de métadonnées internes ni d'acteur admin).
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type->value,
            'amount' => ['minor' => $this->amount_minor, 'currency' => $this->currency],
            'balance_after' => ['minor' => $this->balance_after_minor, 'currency' => $this->currency],
            'related' => $this->related_type === null ? null : ['type' => $this->related_type, 'id' => $this->related_id],
            'description' => $this->description,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
