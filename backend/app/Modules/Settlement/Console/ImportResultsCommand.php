<?php

namespace App\Modules\Settlement\Console;

use App\Modules\Settlement\Services\ResultImportService;
use Illuminate\Console\Command;

/**
 * Importe les résultats des matchs terminés (P5-04). Planifiée selon SPORTS_DATA_RESULTS_CRON ; peut être lancée à la main.
 *
 * Sans --competition, seules les compétitions qui attendent un résultat sont interrogées
 * (ResultImportService::pendingCompetitions) : aucun appel quand aucun match n'est à régler.
 * Coût chez The Odds API : 2 crédits par compétition (scores avec daysFrom).
 */
class ImportResultsCommand extends Command
{
    protected $signature = 'settlement:import-results
        {--competition=* : Clé(s) de compétition, par défaut celles qui attendent un résultat}
        {--days=1 : Nombre de jours à remonter (1 à 3) avec --competition}';

    protected $description = 'Importe les résultats des matchs terminés depuis le fournisseur';

    public function handle(ResultImportService $import): int
    {
        $days = max(1, min(ResultImportService::MAX_DAYS_FROM, (int) $this->option('days')));
        $competitions = $this->option('competition')
            ? array_fill_keys($this->option('competition'), $days)
            : $import->pendingCompetitions();

        if ($competitions === []) {
            $this->info('Aucun match en attente de résultat.');

            return self::SUCCESS;
        }

        $failed = false;
        $rows = [];
        foreach ($competitions as $key => $daysFrom) {
            $report = $import->import($key, $daysFrom);
            $failed = $failed || ! $report->succeeded();
            $rows[] = [
                $key, $report->recorded, $report->corrected, $report->unchanged,
                $report->inProgress, $report->unknown, $report->rejected, $report->error ?? '—',
            ];
        }

        $this->table(['Compétition', 'Nouveaux', 'Corrigés', 'Inchangés', 'En cours', 'Inconnus', 'Rejetés', 'Erreur'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
