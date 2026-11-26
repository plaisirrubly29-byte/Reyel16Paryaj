<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Score exact final en direct (D-20, demande de Rubly du 2026-11-22 : « à 2-1, je peux parier que le match finit 3-1 »).
 *
 * The Odds API ne cote pas le score exact pendant le match, et le score exact d'avant-match (s'il existe) ne contient
 * que quelques scores. À chaque relecture du score (LiveUpdateService), chaque match de football en cours reçoit donc un
 * marché « correct_score » qui contient tous les scores encore possibles jusqu'à live_model.correct_score_extra_goals
 * buts de plus par équipe : à 2-1, de « 2-1 » à « 5-4 ». Les sélections déjà là ne sont jamais supprimées (des tickets
 * peuvent les viser) ; LiveOddsService ne cote et ne montre que celles encore possibles. Clé et sélections identiques au
 * score exact d'avant-match ("3-1") : le règlement sur le score final (OutcomeResolver) ne change pas.
 *
 * La cote enregistrée n'est qu'un repère (affiché quand le marché est suspendu) : la cote pariable est recalculée à
 * chaque lecture par LiveOddsService.
 */
class LiveCorrectScoreMarket
{
    /** Bookmaker affiché à l'administration pour un marché coté par REYEL 16. */
    public const SOURCE = 'reyel16';

    /** Matchs de football en cours de cette compétition. */
    public function syncCompetition(string $competitionKey): void
    {
        if (! str_starts_with($competitionKey, 'soccer')) {
            return;
        }

        Event::query()
            ->where('status', EventStatus::Live)
            ->whereHas('competition', fn ($q) => $q->where('external_key', $competitionKey))
            ->with('competition')
            ->get()
            ->each(fn (Event $event) => $this->sync($event));
    }

    public function sync(Event $event): void
    {
        $sportKey = (string) $event->competition?->external_key;
        if ($event->status !== EventStatus::Live || ! str_starts_with($sportKey, 'soccer')
            || $event->home_score === null || $event->away_score === null
            || $event->live_home_rate === null || $event->live_away_rate === null) {
            return;
        }

        try {
            $probabilities = LiveOddsModel::correctScores(
                (float) $event->live_home_rate,
                (float) $event->live_away_rate,
                (float) ($event->live_draw_boost ?? 1.0),
                (int) $event->home_score,
                (int) $event->away_score,
                LiveOddsService::remainingMinutes($event, now()),
                (int) config('sports_data.live_model.correct_score_extra_goals', 3),
            );
            if ($probabilities === []) {
                return;
            }
            $margin = OddsMargin::rate($sportKey, LiveOddsService::CORRECT_SCORE, true);

            DB::transaction(function () use ($event, $probabilities, $margin) {
                /** @var Market $market */
                $market = $event->markets()->firstOrCreate(['key' => LiveOddsService::CORRECT_SCORE], [
                    'status' => MarketStatus::Open,
                    'odds_version' => 1,
                    'source_bookmaker' => self::SOURCE,
                    'odds_updated_at' => now(),
                ]);

                $missing = array_diff(array_keys($probabilities), $market->selections()->pluck('key')->all());
                if ($missing === []) {
                    return;
                }

                foreach ($missing as $key) {
                    [$home, $away] = LiveOddsService::goals($key);
                    $odds = LiveOddsModel::odds($probabilities[$key], $margin);
                    $market->selections()->create([
                        'key' => $key,
                        'name' => "{$home} - {$away}",
                        'odds' => $odds,
                        'computed_odds' => $odds,
                    ]);
                }

                // Nouvelles issues : nouvelle version du marché (le coupon relit les cotes avant de parier).
                if (! $market->wasRecentlyCreated) {
                    $market->increment('odds_version');
                }
            });
        } catch (Throwable $e) {
            Log::warning('sports_data.live_correct_score_failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
        }
    }
}
