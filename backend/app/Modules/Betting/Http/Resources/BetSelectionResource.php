<?php

namespace App\Modules\Betting\Http\Resources;

use App\Modules\Betting\Models\BetSelection;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sélection d'un ticket, lue dans l'instantané pris à l'acceptation (jamais dans le catalogue du jour).
 * Seul le sport est relu, pour le libellé du marché « plus/moins ».
 *
 * @mixin BetSelection
 */
class BetSelectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'event' => [
                'id' => (string) $this->event_id,
                'name' => $this->event_name,
                'starts_at' => $this->event_starts_at->toIso8601String(),
            ],
            'market' => [
                'key' => $this->market_key,
                'name' => MarketResource::marketName($this->market_key, $this->event?->competition?->sport?->slug),
                'line' => $this->market_line,
            ],
            'selection' => [
                'key' => $this->selection_key,
                'label' => MarketResource::selectionLabel($this->selection_key, $this->market_line),
                'name' => $this->selection_name,
            ],
            'odds' => MarketResource::odds($this->odds),
            'status' => $this->status->value,
        ];
    }
}
