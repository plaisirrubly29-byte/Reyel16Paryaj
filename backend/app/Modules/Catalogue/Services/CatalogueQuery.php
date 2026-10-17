<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture du catalogue public (P3-05) : les événements à venir qui ont au moins un marché ouvert
 * et coté (Market::scopeDisplayable). Une cote trop ancienne reste affichée mais n'est pas pariable
 * (règle de fraîcheur, Market::isBettable, exposée par MarketResource en "bettable").
 * Lecture seule des tables du module SportsData.
 */
class CatalogueQuery
{
    /**
     * @param  ?string  $marketKey  n'exiger que ce marché (ex. "h2h" pour la liste, qui n'affiche que le 1X2)
     * @return Builder<Event>
     */
    public function visibleEvents(?string $marketKey = null): Builder
    {
        return Event::query()
            ->where('status', EventStatus::Scheduled)
            ->where('starts_at', '>', now())
            ->whereHas('markets', fn ($q) => $q->displayable()->when($marketKey, fn ($m) => $m->where('key', $marketKey)))
            ->whereHas('competition', fn ($q) => $q->where('is_active', true)
                ->whereHas('sport', fn ($s) => $s->where('is_active', true)));
    }

    /**
     * Charge l'affichage d'un match : compétition, sport, équipes et marchés affichables,
     * dans un ordre stable (1X2 puis plus/moins ; 1, X, 2 puis plus, moins).
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function withDisplayRelations(Builder $query, ?string $marketKey = null): Builder
    {
        return $query->with([
            'competition.sport',
            'homeTeam',
            'awayTeam',
            'markets' => fn ($q) => $q->displayable()
                ->when($marketKey, fn ($m) => $m->where('key', $marketKey))
                ->orderByRaw("CASE \"key\" WHEN 'h2h' THEN 1 WHEN 'totals' THEN 2 ELSE 3 END")
                ->orderBy('id')
                ->with(['selections' => fn ($s) => $s->orderByRaw("CASE \"key\" WHEN 'home' THEN 1 WHEN 'draw' THEN 2 WHEN 'away' THEN 3 WHEN 'over' THEN 4 WHEN 'under' THEN 5 ELSE 6 END")]),
        ]);
    }
}
