<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\SportsData\Enums\EventStatus;
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
            // Score du moment (match en cours) ; null avant le coup d'envoi ou si le fournisseur ne l'a pas encore donné.
            'score' => $this->status === EventStatus::Live && $this->home_score !== null && $this->away_score !== null
                ? ['home' => $this->home_score, 'away' => $this->away_score]
                : null,
            // Le libellé d'un marché dépend du sport ("Nombre de buts" au football) : on rattache l'événement
            // déjà chargé (avec compétition et sport) pour éviter une requête par marché.
            'markets' => MarketResource::collection($this->markets->each->setRelation('event', $this->resource)),
        ];
    }
}
