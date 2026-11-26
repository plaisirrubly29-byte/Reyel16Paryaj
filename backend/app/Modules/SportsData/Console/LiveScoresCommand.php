<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Services\LiveUpdateService;
use Illuminate\Console\Command;

/**
 * Relit le score des matchs en cours (paris en direct, D-20), 1 crédit par compétition qui a un match en cours.
 * Planifiée toutes les SPORTS_DATA_LIVE_SCORES_SECONDS secondes, seulement quand les paris en direct sont ouverts ;
 * même plafond de crédits du jour que sports-data:live.
 */
class LiveScoresCommand extends Command
{
    protected $signature = 'sports-data:live-scores';

    protected $description = 'Relit le score des matchs en cours pour les paris en direct (1 crédit par compétition)';

    public function handle(LiveUpdateService $live): int
    {
        $outcome = $live->runScores();

        if ($outcome['updated'] === [] && $outcome['skipped'] === []) {
            $this->info('Aucun match en cours.');

            return self::SUCCESS;
        }

        foreach ($outcome['updated'] as $key) {
            $this->line("{$key} : ".($outcome['errors'][$key] ?? 'score à jour'));
        }
        foreach ($outcome['skipped'] as $key) {
            $this->warn("{$key} : reporté (plafond de crédits du jour atteint)");
        }

        $this->line("Crédits du direct aujourd'hui : {$live->creditsSpentToday()} / ".config('sports_data.live_daily_credits'));

        return $outcome['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
