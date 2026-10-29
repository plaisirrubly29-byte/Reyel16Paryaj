<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Betting\Http\Resources\BetSelectionResource;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ticket vu par un administrateur. Joueur réduit à son identifiant et son nom (données minimales, rôle Support) ;
 * le détail ajoute les sélections, l'instantané des règles et, avec ledger.view, les écritures du ticket.
 *
 * @mixin BetTicket
 */
class AdminTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'user' => ['id' => (string) $this->user_id, 'name' => $this->user?->name],
            'wallet_id' => (string) $this->wallet_id,
            'stake' => ['minor' => $this->stake_minor, 'currency' => $this->currency],
            'total_odds' => MarketResource::odds($this->total_odds),
            'potential_payout' => ['minor' => $this->potential_payout_minor, 'currency' => $this->currency],
            'payout' => $this->payout_minor === null ? null : ['minor' => $this->payout_minor, 'currency' => $this->currency],
            'rules_version' => $this->rules_version,
            'placed_at' => $this->placed_at->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            $this->mergeWhen($this->relationLoaded('selections'), fn () => [
                'rules_snapshot' => $this->rules_snapshot,
                'selections' => BetSelectionResource::collection($this->selections),
            ]),
            'ledger_entries' => AdminLedgerEntryResource::collection($this->whenLoaded('ledgerEntries')),
        ];
    }
}
