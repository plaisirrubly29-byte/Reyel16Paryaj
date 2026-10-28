<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement vu par l'administration (P6-02) : origine, synchronisation, marchés de tous statuts.
 * Liste : nombre de marchés par statut ; détail : marchés complets.
 *
 * @mixin Event
 */
class AdminEventResource extends JsonResource
{
    private bool $withMarkets = false;

    /** Détail : inclure les marchés complets (sélections, origine, fraîcheur). */
    public function withMarkets(): static
    {
        $this->withMarkets = true;

        return $this;
    }

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
            'score' => $this->home_score !== null && $this->away_score !== null
                ? ['home' => $this->home_score, 'away' => $this->away_score]
                : null,
            'origin' => ['provider' => $this->provider, 'external_id' => $this->external_id],
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'result_received_at' => $this->result_received_at?->toIso8601String(),
            'markets_count' => collect(MarketStatus::cases())
                ->mapWithKeys(fn (MarketStatus $s) => [$s->value => $this->markets->where('status', $s)->count()]),
            'markets' => $this->when(
                $this->withMarkets,
                // Marchés rattachés à l'événement déjà chargé (sport, origine) : pas de requête par marché.
                fn () => AdminMarketResource::collection($this->markets->each->setRelation('event', $this->resource)),
            ),
        ];
    }
}
