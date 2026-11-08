<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Synchronise matchs et cotes des compétitions configurées (SPORTS_DATA_SPORTS) : clés exactes ("soccer_epl"),
 * motifs ("tennis_*" : tous les tournois de tennis en cours) ou "*" pour toutes les compétitions actives.
 * Sans --competition, les compétitions déjà au catalogue que la liste ne désigne plus en sont retirées.
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
        $configured = array_values(array_filter((array) ($this->option('competition') ?: config('sports_data.sports'))));
        $competitions = $configured;
        $failed = false;

        // Synchro planifiée (sans --competition) : SPORTS_DATA_SPORTS décide aussi de ce que le catalogue affiche.
        if (! $this->option('competition')) {
            $this->applySelection($provider, $configured);
        }

        // Motifs ("*", "tennis_*") : compétitions actives du fournisseur (liste gratuite /sports) qui y correspondent,
        // hors paris « vainqueur final ». Les clés exactes restent demandées même hors saison (le budget les écarte).
        $patterns = array_values(array_filter($configured, fn (string $key) => str_contains($key, '*')));
        if ($patterns !== []) {
            try {
                $matching = collect($provider->sports())
                    ->filter(fn ($s) => $s->active && ! $s->hasOutrights && Str::is($patterns, $s->key))
                    ->pluck('key');
            } catch (SportsDataException $e) {
                $this->error("Liste des compétitions indisponible : {$e->getMessage()}");

                return self::FAILURE;
            }

            $competitions = $matching->merge(array_diff($configured, $patterns))->unique()->sort()->values()->all();
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

    /**
     * Compétitions déjà au catalogue : actives si SPORTS_DATA_SPORTS les désigne (clé exacte ou motif), désactivées
     * sinon. Une compétition désactivée disparaît du catalogue, du direct et des marchés supplémentaires ; ses
     * résultats restent importés tant qu'un pari les attend (ResultImportService).
     *
     * @param  list<string>  $configured
     */
    private function applySelection(SportsDataProvider $provider, array $configured): void
    {
        $changed = ['activées' => [], 'retirées' => []];

        Competition::where('provider', $provider->name())->orderBy('external_key')->get()
            ->each(function (Competition $competition) use ($configured, &$changed) {
                $wanted = Str::is($configured, $competition->external_key);
                if ($competition->is_active !== $wanted) {
                    $competition->update(['is_active' => $wanted]);
                    $changed[$wanted ? 'activées' : 'retirées'][] = $competition->external_key;
                }
            });

        foreach ($changed as $label => $keys) {
            if ($keys !== []) {
                $this->line("Compétitions {$label} au catalogue (SPORTS_DATA_SPORTS) : ".implode(', ', $keys));
            }
        }

        if ($changed['activées'] !== [] || $changed['retirées'] !== []) {
            Log::info('sports_data.selection_changed', $changed);
        }
    }
}
