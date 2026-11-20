<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\SportsData\Models\OddsOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cote fixée à la main (V2-D03, D-18), vue par l'administration.
 *
 * @mixin OddsOverride
 */
class OddsOverrideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->market?->event;
        $person = fn ($user) => $user === null ? null : ['id' => (string) $user->id, 'name' => $user->name];

        return [
            'id' => (string) $this->id,
            'market_id' => (string) $this->market_id,
            'selection' => [
                'id' => (string) $this->selection_id,
                'key' => $this->selection?->key,
                'name' => $this->selection?->name,
            ],
            'event' => $event === null ? null : [
                'id' => (string) $event->id,
                'home_team' => $event->homeTeam?->name,
                'away_team' => $event->awayTeam?->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ],
            'status' => $this->status->value,
            'odds' => MarketResource::odds((string) $this->odds),
            'computed_odds' => MarketResource::odds((string) $this->computed_odds),
            'deviation_percent' => $this->deviationPercent(),
            'requires_approval' => $this->requires_approval,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at->toIso8601String(),
            'requested_by' => $person($this->requester),
            'requested_at' => $this->created_at->toIso8601String(),
            'decided_by' => $person($this->decider),
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'ended_by' => $person($this->ender),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
