<?php

namespace App\Modules\SportsData\Services;

use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Matchs en direct (onglet Live) : score et cotes des matchs en cours.
 *
 * - Seules les compétitions actives qui ont un match commencé depuis moins de live_window_minutes,
 *   sans résultat, sont interrogées : aucun crédit dépensé quand rien ne se joue.
 * - Par compétition : les scores d'abord (un match terminé est enregistré et réglé, Settlement),
 *   puis les cotes des matchs encore en cours (CatalogueSyncService::syncLive).
 * - Plafond journalier de crédits (live_daily_credits) : au-delà, plus d'appel jusqu'au lendemain ;
 *   les cotes en direct vieillissent et ne sont plus pariables (max_live_odds_age_minutes).
 */
class LiveUpdateService
{
    public function __construct(
        private readonly SportsDataProvider $provider,
        private readonly CatalogueSyncService $sync,
        private readonly ResultImportService $results,
        private readonly CreditBudget $budget,
        private readonly LiveCorrectScoreMarket $correctScore,
    ) {}

    /**
     * Compétitions de football dont un match peut encore être parié en direct : commencé depuis moins que la fin des paris
     * (live_model.cutoff_minute + mi-temps + 5 min de marge). Seules celles-là ont besoin du score toutes les 30 s ;
     * les autres sports (pas de paris en direct) et les matchs en fin de partie restent sur run() (toutes les 15 min).
     * Sans ce tri, le plafond de crédits du jour partait en quelques dizaines de minutes et tous les marchés en direct
     * restaient suspendus (« score en cours de vérification », motif score_stale).
     *
     * @return list<string>
     */
    public function liveBettingCompetitions(): array
    {
        $window = (int) config('sports_data.live_model.cutoff_minute') + LiveOddsModel::BREAK_MINUTES + 5;
        $since = now()->subMinutes(min($window, (int) config('sports_data.live_window_minutes')));

        return Event::query()
            ->join('competitions', 'competitions.id', '=', 'events.competition_id')
            ->where('competitions.is_active', true)
            ->where('competitions.external_key', 'like', 'soccer%')
            ->where('events.provider', $this->provider->name())
            ->whereIn('events.status', [EventStatus::Scheduled, EventStatus::Live])
            ->where('events.starts_at', '<=', now())
            ->where('events.starts_at', '>', $since)
            ->distinct()
            ->orderBy('competitions.external_key')
            ->pluck('competitions.external_key')
            ->all();
    }

    /** @return list<string> clés des compétitions qui ont un match en cours */
    public function liveCompetitions(): array
    {
        return Event::query()
            ->join('competitions', 'competitions.id', '=', 'events.competition_id')
            ->where('competitions.is_active', true)
            ->where('events.provider', $this->provider->name())
            ->whereIn('events.status', [EventStatus::Scheduled, EventStatus::Live])
            ->where('events.starts_at', '<=', now())
            ->where('events.starts_at', '>', now()->subMinutes((int) config('sports_data.live_window_minutes')))
            ->distinct()
            ->orderBy('competitions.external_key')
            ->pluck('competitions.external_key')
            ->all();
    }

    /** Coût d'un passage pour une compétition : scores (1 crédit, sans daysFrom) + cotes. */
    public function costPerCompetition(): int
    {
        return 1 + $this->budget->costPerCompetition();
    }

    public function creditsSpentToday(): int
    {
        return (int) Cache::get($this->counterKey(), 0);
    }

    /** @return array{updated: list<string>, skipped: list<string>, errors: array<string, string>} */
    public function run(): array
    {
        $outcome = ['updated' => [], 'skipped' => [], 'errors' => []];
        $cost = $this->costPerCompetition();
        $cap = (int) config('sports_data.live_daily_credits');

        foreach ($this->liveCompetitions() as $key) {
            if ($this->creditsSpentToday() + $cost > $cap) {
                $outcome['skipped'][] = $key;

                continue;
            }

            $this->spend($cost);

            $scores = $this->results->import($key, 0);
            $odds = $this->sync->syncLive($key);
            $this->correctScore->syncCompetition($key);

            $errors = array_filter([$scores->error, $odds->error]);
            if ($errors !== []) {
                $outcome['errors'][$key] = implode(' ; ', $errors);
            }
            $outcome['updated'][] = $key;
        }

        if ($outcome['skipped'] !== []) {
            Log::warning('sports_data.live_cap_reached', ['skipped' => $outcome['skipped'], 'cap' => $cap, 'spent' => $this->creditsSpentToday()]);
        }

        return $outcome;
    }

    /**
     * Scores seulement (1 crédit par compétition de football pariable en direct, liveBettingCompetitions), plus souvent que les cotes (sports-data:live-scores,
     * toutes les live_model.scores_interval_seconds) : un score récent garde les marchés en direct ouverts et un
     * changement de score les suspend (LiveOddsService). Même plafond de crédits du jour que run() : plafond atteint,
     * les scores vieillissent et le direct se ferme de lui-même.
     *
     * @return array{updated: list<string>, skipped: list<string>, errors: array<string, string>}
     */
    public function runScores(): array
    {
        $outcome = ['updated' => [], 'skipped' => [], 'errors' => []];
        $cap = (int) config('sports_data.live_daily_credits');

        foreach ($this->liveBettingCompetitions() as $key) {
            if ($this->creditsSpentToday() + 1 > $cap) {
                $outcome['skipped'][] = $key;

                continue;
            }

            $this->spend(1);
            $scores = $this->results->import($key, 0);
            $this->correctScore->syncCompetition($key);
            if ($scores->error !== null) {
                $outcome['errors'][$key] = $scores->error;
            }
            $outcome['updated'][] = $key;
        }

        if ($outcome['skipped'] !== []) {
            Log::warning('sports_data.live_cap_reached', ['skipped' => $outcome['skipped'], 'cap' => $cap, 'spent' => $this->creditsSpentToday()]);
        }

        return $outcome;
    }

    private function spend(int $credits): void
    {
        $key = $this->counterKey();
        Cache::add($key, 0, now()->endOfDay()->addHour());
        Cache::increment($key, $credits);
    }

    private function counterKey(): string
    {
        return 'sports_data:live_credits:'.now()->toDateString();
    }
}
