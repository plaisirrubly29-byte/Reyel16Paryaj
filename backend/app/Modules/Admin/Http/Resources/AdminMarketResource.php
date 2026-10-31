<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Marché vu par l'administration (P6-02) : tous les statuts, origine et fraîcheur de la cote.
 *
 * @mixin Market
 */
class AdminMarketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->event;
        $maxAge = Market::maxAgeMinutes($event?->status === EventStatus::Live);

        return [
            'id' => (string) $this->id,
            'event_id' => (string) $this->event_id,
            'key' => $this->key,
            'name' => MarketResource::marketName($this->key, $event?->competition?->sport?->slug),
            'line' => $this->line,
            'status' => $this->status->value,
            'suspension' => $this->status === MarketStatus::Suspended ? [
                // automatic : posée par la synchronisation (cotes périmées), levée par des cotes fraîches.
                'automatic' => str_starts_with((string) $this->suspension_reason, CatalogueSyncService::AUTO_SUSPENSION_PREFIX),
                'reason' => $this->suspension_reason,
            ] : null,
            'odds_version' => $this->odds_version,
            'origin' => [
                'provider' => $event?->provider,
                'external_id' => $event?->external_id,
                'bookmaker' => $this->source_bookmaker,
            ],
            'freshness' => [
                'odds_updated_at' => $this->odds_updated_at?->toIso8601String(),
                'age_seconds' => $this->odds_updated_at !== null ? (int) $this->odds_updated_at->diffInSeconds(now(), true) : null,
                'max_age_minutes' => $maxAge,
                'stale' => $this->odds_updated_at === null || $this->odds_updated_at->lt(now()->subMinutes($maxAge)),
            ],
            'bettable' => $this->isBettable(),
            'selections' => $this->whenLoaded('selections', fn () => $this->selections->map(fn ($s) => [
                'id' => (string) $s->id,
                'key' => $s->key,
                'label' => MarketResource::selectionLabel($s->key, $this->line),
                'name' => $s->name,
                'odds' => MarketResource::odds($s->odds),
            ])->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
