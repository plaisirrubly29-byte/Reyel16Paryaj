<?php

namespace App\Modules\Betting\Http\Resources;

use App\Modules\Betting\Models\BetSelection;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\SportsData\Enums\EventStatus;
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
                // Repères d'affichage relus dans le catalogue (Mes paris) : sport, compétition, score du match.
                'sport' => $this->event?->competition?->sport?->slug,
                'competition' => $this->event?->competition === null ? null : [
                    'name' => $this->event->competition->name,
                    'country' => $this->event->competition->country,
                ],
                'status' => $this->event?->status?->value,
                // Score en cours ou final ; null tant qu'il n'est pas connu.
                'score' => in_array($this->event?->status, [EventStatus::Live, EventStatus::Finished], true)
                    && $this->event->home_score !== null && $this->event->away_score !== null
                    ? ['home' => $this->event->home_score, 'away' => $this->event->away_score]
                    : null,
            ],
            'market' => [
                'id' => (string) $this->market_id,
                'key' => $this->market_key,
                'name' => MarketResource::marketName($this->market_key, $this->event?->competition?->sport?->slug),
                'line' => $this->market_line,
            ],
            'selection' => [
                // Sélection du catalogue : sert à rejouer le ticket (coupon recomposé avec les cotes du moment).
                'id' => (string) $this->selection_id,
                'key' => $this->selection_key,
                'label' => MarketResource::selectionLabel($this->selection_key, $this->market_line),
                'name' => $this->selection_name,
            ],
            'odds' => MarketResource::odds($this->odds),
            'status' => $this->status->value,
        ];
    }
}
