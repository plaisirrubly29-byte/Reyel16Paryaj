<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Services\LiveUpdateService;
use Illuminate\Console\Command;

/**
 * Met à jour le score et les cotes des matchs en cours (onglet Live). Planifiée selon SPORTS_DATA_LIVE_CRON ;
 * n'appelle le fournisseur que s'il y a un match en cours, dans la limite de SPORTS_DATA_LIVE_DAILY_CREDITS.
 */
class LiveUpdateCommand extends Command
{
    protected $signature = 'sports-data:live';

    protected $description = 'Met à jour le score et les cotes des matchs en cours (consomme des crédits)';

    public function handle(LiveUpdateService $live): int
    {
        $outcome = $live->run();

        if ($outcome['updated'] === [] && $outcome['skipped'] === []) {
            $this->info('Aucun match en cours.');

            return self::SUCCESS;
        }

        foreach ($outcome['updated'] as $key) {
            $this->line("{$key} : ".($outcome['errors'][$key] ?? 'à jour'));
        }
        foreach ($outcome['skipped'] as $key) {
            $this->warn("{$key} : reporté (plafond de crédits du jour atteint)");
        }

        $this->line("Crédits du direct aujourd'hui : {$live->creditsSpentToday()} / ".config('sports_data.live_daily_credits'));

        return $outcome['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
