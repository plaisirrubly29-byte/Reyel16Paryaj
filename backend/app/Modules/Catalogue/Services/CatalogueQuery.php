<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\HouseMarkets;
use App\Modules\SportsData\Services\LiveOddsModel;
use App\Modules\SportsData\Services\LiveOddsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture du catalogue public (P3-05) : les événements à venir ou en cours qui ont au moins un marché ouvert
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
            // Match masqué par un administrateur (motif dans hidden_reason) : retiré du site.
            ->whereNull('hidden_at')
            // À venir, ou en cours (statut "live" posé par la synchro en direct : onglet Live). Au-delà de la fenêtre
            // du direct, le match n'est plus suivi (fournisseur en panne, crédits épuisés) : il n'est plus montré « en direct ».
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('status', EventStatus::Scheduled)->where('starts_at', '>', now()))
                ->orWhere(fn ($s) => $s->where('status', EventStatus::Live)
                    ->where('starts_at', '>', now()->subMinutes((int) config('sports_data.live_window_minutes')))
                    ->whereNot(fn ($finished) => $this->finishedFootball($finished))))
            ->whereHas('markets', fn ($q) => $q->displayable()->when($marketKey, fn ($m) => $m->where('key', $marketKey)))
            ->whereHas('competition', fn ($q) => $q->where('is_active', true)
                ->whereHas('sport', fn ($s) => $s->where('is_active', true)));
    }

    /**
     * Match de football presque sûrement fini, mais pas encore « terminé » (le résultat arrive par
     * settlement:import-results, toutes les 10 min) : retiré de l'onglet Live au lieu d'y rester « Fermé ». Rubly,
     * 2026-11-23 : plus de 15 matchs fermés affichés à la fois. Fini = fermeture de sécurité du direct passée, ou, après
     * la fin normale du match (96 min de jeu + mi-temps), plus aucun score reçu depuis score_unavailable_close_seconds.
     * Seulement quand les paris en direct sont ouverts : le score n'est relu toutes les 30 s (ou moins) qu'à ce moment-là.
     *
     * @param  Builder<Event>  $query
     */
    private function finishedFootball(Builder $query): void
    {
        if (! (bool) config('betting.allow_live')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('competition', fn ($c) => $c->where('external_key', 'like', 'soccer%'))
            ->where(fn ($q) => $q
                ->where('starts_at', '<=', now()->subMinutes(LiveOddsService::closeAfterMinutes()))
                ->orWhere(fn ($late) => $late
                    ->where('starts_at', '<=', now()->subMinutes(LiveOddsModel::PLAYING_MINUTES + LiveOddsModel::BREAK_MINUTES))
                    ->where(fn ($s) => $s->whereNull('score_checked_at')
                        ->orWhere('score_checked_at', '<', now()->subSeconds(LiveOddsService::scoreUnavailableCloseSeconds())))));
    }

    /**
     * Charge l'affichage d'un match : compétition, sport, équipes et marchés affichables,
     * dans un ordre stable (1X2, double chance, plus/moins, les deux marquent ; 1, X, 2 ; 1X, 12, X2 ; plus, moins ; oui, non).
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function withDisplayRelations(Builder $query, ?string $marketKey = null): Builder
    {
        // Repère « Spécial R16 » de la liste : le match a au moins un marché maison affichable (V2-D10).
        return $query->withExists(['markets as has_house_markets' => fn ($q) => $q->displayable()->whereIn('key', HouseMarkets::KEYS)])->with([
            'competition.sport',
            'homeTeam',
            'awayTeam',
            'markets' => fn ($q) => $q->displayable()
                ->when($marketKey, fn ($m) => $m->where('key', $marketKey))
                ->orderByRaw("CASE \"key\" WHEN 'h2h' THEN 1 WHEN 'double_chance' THEN 2 WHEN 'totals' THEN 3 WHEN 'btts' THEN 4"
                    ." WHEN 'draw_no_bet' THEN 5 WHEN 'spreads' THEN 6 WHEN 'correct_score' THEN 7 WHEN 'alternate_totals' THEN 8"
                    ." WHEN 'team_totals' THEN 9 WHEN 'alternate_spreads' THEN 10 ELSE 11 END")
                ->orderBy('id')
                ->with(['selections' => fn ($s) => $s->orderByRaw(
                    "CASE \"key\" WHEN 'home' THEN 1 WHEN 'draw' THEN 2 WHEN 'away' THEN 3 WHEN 'home_draw' THEN 4 WHEN 'home_away' THEN 5"
                    ." WHEN 'draw_away' THEN 6 WHEN 'over' THEN 7 WHEN 'under' THEN 8 WHEN 'yes' THEN 9 WHEN 'no' THEN 10 ELSE 11 END"
                )->orderBy('id')]), // score exact : dans l'ordre du fournisseur
        ]);
    }
}
