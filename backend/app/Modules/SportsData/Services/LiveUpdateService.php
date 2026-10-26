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
    ) {}

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
