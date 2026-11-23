<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Selection;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cotes en direct proposées aux joueurs (D-20, option « Modèle + The Odds »), calculées à la demande à chaque lecture :
 * rien n'est écrit en base chaque seconde. Le catalogue, l'onglet Live (GET /live/odds) et le pari lisent tous cette
 * même cote.
 *
 * Un marché d'un match en cours n'est ouvert que si TOUT est vrai (sinon « suspendu », avec le motif) :
 *  - paris en direct autorisés (betting.allow_live, le coupe-circuit) ;
 *  - football, 1X2, plus/moins ou score exact final, marché ouvert (pas suspendu par un administrateur) ;
 *  - match pas fini : pas de minute limite, les paris restent ouverts temps additionnel compris jusqu'au résultat
 *    final ; fermé seulement close_after_minutes après l'heure de début prévue (filet de sécurité) ;
 *  - score reçu il y a moins de max_score_age_seconds (sans score depuis score_unavailable_close_seconds : fermé), et
 *    pas de changement de score depuis moins de action_suspension_seconds (10 s, levée d'elle-même ensuite) ;
 *  - issue pas déjà acquise ni trop probable (max_probability) ;
 *  - modèle calé (force des équipes connue, LiveOddsModel).
 *
 * Cote = 1 / (probabilité du modèle × (1 + marge du direct, OddsMargin + live_extra)), plafonnée par la dernière cote
 * en direct de The Odds API (marge comprise) quand elle date d'après le dernier changement de score et reste assez
 * récente (max_live_odds_age_minutes) : jamais mieux payée que le marché.
 *
 * Score exact final (correct_score) : coté par le modèle seul à partir du score du moment (The Odds API n'en donne pas
 * en direct). Seuls les scores encore possibles sont proposés : à 2-1, « 3-1 » oui, « 1-0 » jamais. Les sélections
 * manquantes sont créées à chaque relecture du score (LiveCorrectScoreMarket).
 */
class LiveOddsService
{
    /** Marchés proposés en direct (D-20 §4.1, plus le score exact final demandé par Rubly le 2026-11-22). */
    public const MARKETS = ['h2h', 'totals', self::CORRECT_SCORE];

    public const CORRECT_SCORE = 'correct_score';

    public const LIVE_CLOSED = 'live_closed';

    public const NOT_AVAILABLE = 'not_available';

    public const SUSPENDED = 'suspended';

    public const SCORE_STALE = 'score_stale';

    public const SCORE_CHANGED = 'score_changed';

    public const CLOSING = 'closing';

    public const DECIDED = 'decided';

    /** Plus de score reçu depuis score_unavailable_close_seconds (match arrêté, pas suivi en direct) : direct fermé. */
    public const SCORE_UNAVAILABLE = 'score_unavailable';

    /**
     * Durée de la suspension après une action (but, but refusé par la VAR, score corrigé) : levée d'elle-même ensuite,
     * sans attendre une nouvelle lecture du score. 10 s par défaut (live_model.action_suspension_seconds).
     */
    public static function actionSuspensionSeconds(): int
    {
        return max(0, (int) config('sports_data.live_model.action_suspension_seconds', 10));
    }

    /** Délai sans score au-delà duquel le direct du match est fermé, jamais plus court que l'âge maximal du score. */
    public static function scoreUnavailableCloseSeconds(): int
    {
        return max(
            (int) config('sports_data.live_model.max_score_age_seconds', 90),
            (int) config('sports_data.live_model.score_unavailable_close_seconds', 300),
        );
    }

    /** Fermeture de sécurité du direct, en minutes après l'heure de début prévue (jamais avant la fin normale du match). */
    public static function closeAfterMinutes(): int
    {
        return max(
            LiveOddsModel::PLAYING_MINUTES + LiveOddsModel::BREAK_MINUTES,
            (int) config('sports_data.live_model.close_after_minutes', 150),
        );
    }

    /** Temps de jeu restant compté par le modèle, au moins live_model.min_remaining_minutes tant que le match dure. */
    public static function remainingMinutes(Event $event, CarbonInterface $now): float
    {
        return LiveOddsModel::remainingMinutes($event->starts_at, $now,
            max(0.0, (float) config('sports_data.live_model.min_remaining_minutes', 2)));
    }

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
        if ($market->key === self::CORRECT_SCORE) {
            return $this->correctScoreQuote($market, $event, $sportKey, $home, $away, $now);
        }

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
            self::remainingMinutes($event, $now),
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
     * Sélections à montrer pendant le match. Le score exact ne garde que les scores encore possibles (à 2-1 : ni « 1-0 »
     * ni « 2-0 »), seulement ceux qui sont cotés quand le marché est ouvert, dans l'ordre domicile puis extérieur.
     * Les autres marchés gardent toutes leurs sélections.
     *
     * @template TSelection of Selection
     *
     * @param  Collection<int, TSelection>  $selections
     * @return Collection<int, TSelection>
     */
    public function visibleSelections(Market $market, LiveQuote $quote, Collection $selections): Collection
    {
        if ($market->key !== self::CORRECT_SCORE) {
            return $selections;
        }

        $home = (int) $market->event?->home_score;
        $away = (int) $market->event?->away_score;

        return $selections
            ->filter(fn (Selection $s) => $quote->open
                ? isset($quote->odds[$s->key])
                : (($goals = self::goals($s->key)) !== null && $goals[0] >= $home && $goals[1] >= $away))
            ->sortBy(fn (Selection $s) => self::goals($s->key) ?? [PHP_INT_MAX, PHP_INT_MAX])
            ->values();
    }

    /** @return array{0: int, 1: int}|null buts domicile et extérieur d'une clé de score exact ("3-1") */
    public static function goals(string $key): ?array
    {
        return preg_match('/^(\d{1,2})-(\d{1,2})$/', $key, $m) === 1 ? [(int) $m[1], (int) $m[2]] : null;
    }

    /**
     * Score exact final : probabilité de chaque score encore possible (modèle, sans plafond du fournisseur : The Odds
     * API ne cote pas le score exact en direct). Un score impossible, ou trop improbable (cote au-delà de
     * live_model.max_correct_score_odds), n'a pas de cote : il n'est ni affiché ni pariable.
     */
    private function correctScoreQuote(Market $market, Event $event, string $sportKey, int $home, int $away,
        CarbonInterface $now): LiveQuote
    {
        $config = config('sports_data.live_model');
        $p = LiveOddsModel::correctScores(
            (float) $event->live_home_rate,
            (float) $event->live_away_rate,
            (float) ($event->live_draw_boost ?? 1.0),
            $home,
            $away,
            self::remainingMinutes($event, $now),
            (int) ($config['correct_score_extra_goals'] ?? 3),
        );
        if ($p === [] || max($p) > (float) ($config['max_probability'] ?? 0.95)) {
            return LiveQuote::closed(self::DECIDED);
        }

        $margin = OddsMargin::rate($sportKey, self::CORRECT_SCORE, true);
        $maxOdds = (float) ($config['max_correct_score_odds'] ?? 100);
        $selections = $market->relationLoaded('selections') ? $market->selections : $market->selections()->get();
        $odds = [];
        foreach ($selections as $selection) {
            if (! isset($p[$selection->key])) {
                continue; // score déjà dépassé, ou trop loin du score du moment
            }
            $value = LiveOddsModel::odds($p[$selection->key], $margin);
            if ((float) $value <= $maxOdds) {
                $odds[$selection->key] = $value;
            }
        }

        return $odds === [] ? LiveQuote::closed(self::NOT_AVAILABLE) : new LiveQuote(true, null, $odds);
    }

    /**
     * Motif commun à tous les marchés du match, ou null s'il est pariable : score trop ancien, score qui vient de
     * changer, fin de match, modèle pas calé.
     */
    public function eventState(Event $event, CarbonInterface $now): ?string
    {
        $config = config('sports_data.live_model');

        // Pas de minute limite : temps additionnel compris, on parie jusqu'au résultat final (le match passe alors en
        // « terminé »). Seul filet : bien après l'heure de fin possible, le direct est fermé, score relu ou non.
        if (LiveOddsModel::elapsedMinutes($event->starts_at, $now) >= self::closeAfterMinutes()) {
            return self::CLOSING;
        }

        $scoreKnown = $event->home_score !== null && $event->away_score !== null && $event->score_checked_at !== null;
        if (! $scoreKnown || $event->score_checked_at->lt($now->copy()->subSeconds((int) $config['max_score_age_seconds']))) {
            // Retard passager : suspendu, rouvert au prochain score reçu. Pas de score depuis trop longtemps (match
            // arrêté, ou que le fournisseur ne suit pas en direct) : fermé, plutôt que « suspendu » pour toujours.
            $since = $scoreKnown ? $event->score_checked_at : $event->starts_at;

            return $since->lt($now->copy()->subSeconds(self::scoreUnavailableCloseSeconds()))
                ? self::SCORE_UNAVAILABLE : self::SCORE_STALE;
        }
        if ($event->score_changed_at !== null
            && $event->score_changed_at->gt($now->copy()->subSeconds(self::actionSuspensionSeconds()))) {
            return self::SCORE_CHANGED;
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
