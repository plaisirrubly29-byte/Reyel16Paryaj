<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\SportsData\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Match du catalogue. En liste : le marché principal (1X2) ; en détail : tous les marchés pariables.
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $competition = $this->competition;

        return [
            'id' => (string) $this->id,
            'sport' => ['slug' => $competition->sport->slug, 'name' => $competition->sport->name],
            'competition' => ['id' => (string) $competition->id, 'name' => $competition->name, 'country' => $competition->country],
            'home_team' => $this->homeTeam->name,
            'away_team' => $this->awayTeam->name,
            'starts_at' => $this->starts_at->toIso8601String(),
            'status' => $this->status->value,
            'markets' => MarketResource::collection($this->markets),
        ];
    }
}
