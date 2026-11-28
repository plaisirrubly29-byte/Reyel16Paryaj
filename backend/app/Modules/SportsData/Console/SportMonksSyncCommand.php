<?php

namespace App\Modules\SportsData\Console;

use App\Modules\Settlement\Services\ResultCorrectionService;
use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\SportMonksProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\LiveCorrectScoreMarket;
use App\Modules\SportsData\Services\LiveOddsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Football synchronisé depuis SportMonks, à côté de The Odds API (qui garde les autres sports) :
 *
 * - sans option : matchs et cotes des ligues de l'abonnement (SPORTMONKS_LEAGUES), 1 appel par ligue ; les ligues
 *   que la liste ne désigne plus quittent le catalogue. Planifiée selon SPORTMONKS_SYNC_CRON.
 * - --results : résultats des matchs commencés sans résultat (même règle que settlement:import-results).
 * - --live : score des matchs en cours (1 appel pour toutes les ligues), chaque minute, seulement s'il y en a.
 *
 * Mêmes services que pour The Odds API (CatalogueSyncService, ResultImportService), construits avec SportMonks :
 * matchs, compétitions et résultats portent le fournisseur « sportmonks ».
 */
class SportMonksSyncCommand extends Command
{
    protected $signature = 'sports-data:sportmonks
        {--results : Importe les résultats des matchs terminés}
        {--live : Relit le score des matchs en cours}';

    protected $description = 'Synchronise le football depuis SportMonks (matchs et cotes, résultats ou scores en direct)';

    public function handle(SportMonksProvider $provider, ResultRecorder $recorder, ResultCorrectionService $corrections, LiveCorrectScoreMarket $correctScore): int
    {
        if (empty(config('sports_data.sportmonks.key'))) {
            $this->warn('SportMonks désactivé : SPORTMONKS_API_KEY est vide.');

            return self::SUCCESS;
        }

        $results = new ResultImportService($provider, $recorder, $corrections);

        if ($this->option('live')) {
            return $this->live($provider, $results, $correctScore);
        }

        if ($this->option('results')) {
            return $this->results($results);
        }

        return $this->catalogue($provider, new CatalogueSyncService($provider));
    }

    private function catalogue(SportMonksProvider $provider, CatalogueSyncService $sync): int
    {
        try {
            $leagues = collect($provider->sports())->filter(fn ($s) => $s->active)->pluck('key');
        } catch (SportsDataException $e) {
            $this->error("Ligues SportMonks indisponibles : {$e->getMessage()}");
            Log::warning('sports_data.sportmonks_leagues_unavailable', ['reason' => $e->reason]);

            return self::FAILURE;
        }

        $wanted = (array) config('sports_data.sportmonks.leagues');
        if (! in_array('*', $wanted, true)) {
            $leagues = $leagues->filter(fn (string $key) => in_array((string) $provider->leagueForKey($key), $wanted, true));
        }
        $leagues = $leagues->unique()->sort()->values();

        // Ligues retirées de la liste (ou de l'abonnement) : hors du catalogue ; leurs résultats restent importés
        // tant qu'un pari les attend.
        Competition::where('provider', $provider->name())->get()->each(function (Competition $competition) use ($leagues) {
            $active = $leagues->contains($competition->external_key);
            if ($competition->is_active !== $active) {
                $competition->update(['is_active' => $active]);
            }
        });

        $this->info('Fournisseur : sportmonks — ligues : '.$leagues->implode(', '));

        $failed = false;
        $rows = [];
        foreach ($leagues as $key) {
            $report = $sync->sync($key);
            $failed = $failed || ! $report->succeeded();
            $rows[] = [$key, $report->eventsCreated, $report->eventsUpdated, $report->oddsChanged, $report->skipped, $report->error ?? 'OK'];
        }

        $this->table(['Compétition', 'Créés', 'Mis à jour', 'Cotes changées', 'Ignorés', 'État'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function results(ResultImportService $results): int
    {
        $failed = false;
        foreach ($results->pendingCompetitions() as $key => $days) {
            $report = $results->import($key, $days);
            $failed = $failed || $report->error !== null;
            $this->line("{$key} : ".($report->error ?? "{$report->recorded} résultat(s), {$report->inProgress} en cours"));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function live(SportMonksProvider $provider, ResultImportService $results, LiveCorrectScoreMarket $correctScore): int
    {
        $window = max(LiveOddsService::closeAfterMinutes(), (int) config('sports_data.live_window_minutes'));

        $competitions = Event::query()
            ->join('competitions', 'competitions.id', '=', 'events.competition_id')
            ->where('events.provider', $provider->name())
            ->whereIn('events.status', [EventStatus::Scheduled, EventStatus::Live])
            ->where('events.starts_at', '<=', now())
            ->where('events.starts_at', '>', now()->subMinutes($window))
            ->distinct()
            ->orderBy('competitions.external_key')
            ->pluck('competitions.external_key');

        if ($competitions->isEmpty()) {
            $this->info('Aucun match SportMonks en cours.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($competitions as $key) {
            $report = $results->import($key, 0);
            $correctScore->syncCompetition($key);
            $failed = $failed || $report->error !== null;
            $this->line("{$key} : ".($report->error ?? 'score à jour'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
