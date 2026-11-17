<?php

namespace App\Modules\Settlement\Console;

use App\Modules\Settlement\Services\StatisticsImportService;
use Illuminate\Console\Command;

/**
 * Importe les statistiques des matchs terminés (V2-D09) : score à la mi-temps, corners, cartons. Planifiée selon
 * STATISTICS_CRON quand STATISTICS_PROVIDER est réglé ; seuls les matchs avec un pari en attente sur ces marchés
 * sont demandés au fournisseur.
 */
class ImportStatisticsCommand extends Command
{
    protected $signature = 'settlement:import-statistics';

    protected $description = 'Importe les statistiques (mi-temps, corners, cartons) des matchs terminés avec des paris en attente';

    public function handle(StatisticsImportService $import): int
    {
        if (! StatisticsImportService::enabled()) {
            $this->warn('Statistiques désactivées (STATISTICS_PROVIDER vide).');

            return self::SUCCESS;
        }

        $report = $import->import();
        $this->table(['Matchs à traiter', 'Enregistrés', 'Introuvables', 'Requêtes', 'Erreur'], [[
            $report['candidates'], $report['recorded'], $report['not_found'], $report['requests'], $report['error'] ?? '—',
        ]]);

        return $report['error'] === null ? self::SUCCESS : self::FAILURE;
    }
}
