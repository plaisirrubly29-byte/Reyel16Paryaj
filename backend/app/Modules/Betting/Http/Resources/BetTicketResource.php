<?php

namespace App\Modules\Betting\Http\Resources;

use App\Modules\Betting\Models\BetTicket;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ticket vu par son joueur : conditions acceptées et résultat. Ni portefeuille, ni instantané des règles
 * (données internes, consultables par l'administration).
 *
 * @mixin BetTicket
 */
class BetTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'stake' => ['minor' => $this->stake_minor, 'currency' => $this->currency],
            'total_odds' => MarketResource::odds($this->total_odds),
            // Montant calculé par le serveur à l'acceptation : le frontend l'affiche tel quel.
            'potential_payout' => ['minor' => $this->potential_payout_minor, 'currency' => $this->currency],
            'payout' => $this->payout_minor === null ? null : ['minor' => $this->payout_minor, 'currency' => $this->currency],
            'placed_at' => $this->placed_at->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'selections' => BetSelectionResource::collection($this->whenLoaded('selections')),
        ];
    }
}
