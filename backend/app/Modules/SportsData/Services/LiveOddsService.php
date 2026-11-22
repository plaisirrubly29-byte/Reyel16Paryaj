<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use Carbon\CarbonInterface;

/**
 * Cotes en direct proposées aux joueurs (D-20, option « Modèle + The Odds »), calculées à la demande à chaque lecture :
 * rien n'est écrit en base chaque seconde. Le catalogue, l'onglet Live (GET /live/odds) et le pari lisent tous cette
 * même cote.
 *
 * Un marché d'un match en cours n'est ouvert que si TOUT est vrai (sinon « suspendu », avec le motif) :
 *  - paris en direct autorisés (betting.allow_live, le coupe-circuit) ;
 *  - football, 1X2 ou plus/moins, marché ouvert (pas suspendu par un administrateur) ;
 *  - score reçu il y a moins de max_score_age_seconds, et pas de changement de score depuis moins de
 *    score_change_cooldown_seconds ;
 *  - avant la minute cutoff_minute, issue pas déjà acquise ni trop probable (max_probability) ;
 *  - modèle calé (force des équipes connue, LiveOddsModel).
 *
 * Cote = 1 / (probabilité du modèle × (1 + marge du direct, OddsMargin + live_extra)), plafonnée par la dernière cote
 * en direct de The Odds API (marge comprise) quand elle date d'après le dernier changement de score et reste assez
 * récente (max_live_odds_age_minutes) : jamais mieux payée que le marché.
 */
class LiveOddsService
{
    /** Marchés proposés en direct (D-20 §4.1). */
    public const MARKETS = ['h2h', 'totals'];

    public const LIVE_CLOSED = 'live_closed';

    public const NOT_AVAILABLE = 'not_available';

    public const SUSPENDED = 'suspended';

    public const SCORE_STALE = 'score_stale';

    public const SCORE_CHANGED = 'score_changed';

    public const CLOSING = 'closing';

    public const DECIDED = 'decided';

    /** Match en cours : ses cotes viennent du modèle, pas de la base. */
    public function applies(Market $market): bool
    {
        return $market->event?->status === EventStatus::Live;
    }

    public function quote(Market $market, ?CarbonInterface $now = null): LiveQuote
    {
        $now ??= now();
        $event = $market->event;

        if (! (bool) config('betting.allow_live')) {
            return LiveQuote::closed(self::LIVE_CLOSED);
        }
        $sportKey = (string) $event->competition?->external_key;
        if (! str_starts_with($sportKey, 'soccer') || ! in_array($market->key, self::MARKETS, true)) {
            return LiveQuote::closed(self::NOT_AVAILABLE);
        }
        if ($market->status !== MarketStatus::Open) {
            return LiveQuote::closed(self::SUSPENDED);
        }

        $state = $this->eventState($event, $now);
        if ($state !== null) {
            return LiveQuote::closed($state);
        }

        $home = (int) $event->home_score;
        $away = (int) $event->away_score;
        $line = $market->line === null ? null : (float) $market->line;
        if ($market->key === 'totals' && ($line === null || $home + $away > $line)) {
            return LiveQuote::closed(self::DECIDED); // « plus » déjà gagné
        }

        $p = LiveOddsModel::probabilities(
            (float) $event->live_home_rate,
            (float) $event->live_away_rate,
            (float) ($event->live_draw_boost ?? 1.0),
            $home,
            $away,
            LiveOddsModel::remainingMinutes($event->starts_at, $now),
            $market->key === 'totals' ? $line : null,
        );

        $selections = $market->relationLoaded('selections') ? $market->selections : $market->selections()->get();
        $keys = $market->key === 'h2h' ? ['home', 'draw', 'away'] : ['over', 'under'];
        if ($selections->pluck('key')->sort()->values()->all() !== collect($keys)->sort()->values()->all()) {
            return LiveQuote::closed(self::NOT_AVAILABLE);
        }
        foreach ($keys as $key) {
            if ($p[$key] === null || $p[$key] > (float) config('sports_data.live_model.max_probability', 0.95)) {
                return LiveQuote::closed(self::DECIDED);
            }
        }

        $margin = OddsMargin::rate($sportKey, $market->key, true);
        $cap = $this->providerCapApplies($market, $event, $now);
        $odds = [];
        foreach ($selections as $selection) {
            $value = LiveOddsModel::odds($p[$selection->key], $margin);
            if ($cap && bccomp((string) $selection->odds, $value, 3) < 0) {
                $value = bcadd((string) $selection->odds, '0', 3);
            }
            $odds[$selection->key] = $value;
        }

        return new LiveQuote(true, null, $odds);
    }

    /**
     * Motif commun à tous les marchés du match, ou null s'il est pariable : score trop ancien, score qui vient de
     * changer, fin de match, modèle pas calé.
     */
    public function eventState(Event $event, CarbonInterface $now): ?string
    {
        $config = config('sports_data.live_model');

        if ($event->home_score === null || $event->away_score === null || $event->score_checked_at === null
            || $event->score_checked_at->lt($now->copy()->subSeconds((int) $config['max_score_age_seconds']))) {
            return self::SCORE_STALE;
        }
        if ($event->score_changed_at !== null
            && $event->score_changed_at->gt($now->copy()->subSeconds((int) $config['score_change_cooldown_seconds']))) {
            return self::SCORE_CHANGED;
        }
        $minute = LiveOddsModel::minute($event->starts_at, $now);
        if ($minute !== null && $minute >= (int) $config['cutoff_minute']) {
            return self::CLOSING;
        }
        if ($event->live_home_rate === null || $event->live_away_rate === null) {
            return self::NOT_AVAILABLE;
        }

        return null;
    }

    /**
     * La dernière cote de The Odds API plafonne la nôtre si elle a été prise pendant le match, après le dernier
     * changement de score (une cote d'avant le but ne vaut plus rien) et reste assez récente.
     */
    private function providerCapApplies(Market $market, Event $event, CarbonInterface $now): bool
    {
        $at = $market->odds_updated_at;

        return $at !== null
            && $at->gt($event->starts_at)
            && ($event->score_changed_at === null || $at->gte($event->score_changed_at))
            && $at->gte($now->copy()->subMinutes(Market::maxAgeMinutes(true)));
    }
}
