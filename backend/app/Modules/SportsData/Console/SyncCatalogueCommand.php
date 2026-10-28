<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Synchronise matchs et cotes des compétitions configurées (SPORTS_DATA_SPORTS, ou "*" pour toutes les actives).
 * Planifiée selon SPORTS_DATA_SYNC_CRON ; peut être lancée à la main.
 * Coût : 1 appel par compétition (marchés × régions crédits chez The Odds API).
 *
 * Budget (SPORTS_DATA_BUDGET) : seules les compétitions que le budget de cette synchro permet de payer
 * sont appelées (les moins récemment synchronisées d'abord), jamais celles hors saison. --force ignore
 * le budget ; le seuil SPORTS_DATA_MIN_REMAINING du fournisseur s'applique toujours.
 */
class SyncCatalogueCommand extends Command
{
    protected $signature = 'sports-data:sync
        {--competition=* : Clé(s) de compétition, par défaut SPORTS_DATA_SPORTS}
        {--force : Ignore le budget de crédits (le seuil de quota minimal reste appliqué)}';

    protected $description = 'Synchronise les matchs et les cotes depuis le fournisseur (consomme des crédits)';

    public function handle(CatalogueSyncService $sync, SportsDataProvider $provider, CreditBudget $budget): int
    {
        $competitions = array_values($this->option('competition') ?: config('sports_data.sports'));
        $failed = false;

        // "*" : toutes les compétitions actives du fournisseur (liste gratuite /sports), hors paris « vainqueur final ».
        if (in_array('*', $competitions, true)) {
            try {
                $competitions = collect($provider->sports())
                    ->filter(fn ($s) => $s->active && ! $s->hasOutrights)
                    ->pluck('key')->sort()->values()->all();
            } catch (SportsDataException $e) {
                $this->error("Liste des compétitions indisponible : {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info("Fournisseur : {$provider->name()} — compétitions : ".implode(', ', $competitions));

        if ($budget->enabled() && ! $this->option('force')) {
            $plan = $budget->plan($competitions);
            $competitions = $plan->sync;

            $this->line("Budget de cette synchro : {$plan->perRunBudget} crédits ({$plan->costPerCompetition} par compétition).");

            if ($plan->skipped !== []) {
                $this->table(['Compétition reportée', 'Motif'], collect($plan->skipped)->map(fn ($reason, $key) => [$key, $reason])->values());
            }

            if ($plan->skippedForBudget() !== []) {
                Log::warning('sports_data.budget_skip', [
                    'skipped' => $plan->skippedForBudget(),
                    'budget' => $plan->perRunBudget,
                    'remaining' => $budget->remaining(),
                    'runs_left' => $budget->runsLeft(),
                ]);
            }

            if ($competitions === []) {
                $this->warn('Aucune compétition synchronisée (budget ou hors saison).');

                return self::SUCCESS;
            }
        }

        $rows = [];
        foreach ($competitions as $key) {
            $report = $sync->sync($key);
            $failed = $failed || ! $report->succeeded();
            if ($report->succeeded()) {
                $budget->markSynced($key);
            }
            $rows[] = [$key, $report->eventsCreated, $report->eventsUpdated, $report->oddsChanged, $report->skipped, $report->error ?? 'OK'];
        }

        $this->table(['Compétition', 'Créés', 'Mis à jour', 'Cotes changées', 'Ignorés', 'État'], $rows);

        if ($quota = $provider->quota()) {
            $this->line("Quota restant : {$quota->remaining} requêtes.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
