<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Console\Command;

/**
 * Synchronise matchs et cotes des compétitions configurées (SPORTS_DATA_SPORTS).
 * Planifiée selon SPORTS_DATA_SYNC_CRON ; peut être lancée à la main.
 * Coût : 1 appel par compétition (marchés × régions crédits chez The Odds API).
 */
class SyncCatalogueCommand extends Command
{
    protected $signature = 'sports-data:sync {--competition=* : Clé(s) de compétition, par défaut SPORTS_DATA_SPORTS}';

    protected $description = 'Synchronise les matchs et les cotes depuis le fournisseur (consomme des crédits)';

    public function handle(CatalogueSyncService $sync, SportsDataProvider $provider): int
    {
        $competitions = $this->option('competition') ?: config('sports_data.sports');
        $failed = false;

        $this->info("Fournisseur : {$provider->name()} — compétitions : ".implode(', ', $competitions));

        $rows = [];
        foreach ($competitions as $key) {
            $report = $sync->sync($key);
            $failed = $failed || ! $report->succeeded();
            $rows[] = [$key, $report->eventsCreated, $report->eventsUpdated, $report->oddsChanged, $report->skipped, $report->error ?? 'OK'];
        }

        $this->table(['Compétition', 'Créés', 'Mis à jour', 'Cotes changées', 'Ignorés', 'État'], $rows);

        if ($quota = $provider->quota()) {
            $this->line("Quota restant : {$quota->remaining} requêtes.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
