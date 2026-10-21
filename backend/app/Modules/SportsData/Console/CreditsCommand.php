<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Console\Command;

/**
 * État des crédits du fournisseur : restants, période, synchros à venir, budget par synchro, projection
 * et consommation de la période. Lit uniquement le cache et le journal : aucun appel au fournisseur.
 */
class CreditsCommand extends Command
{
    protected $signature = 'sports-data:credits {--json : Sortie JSON}';

    protected $description = 'Affiche le budget de crédits du fournisseur et la consommation de la période (sans appel)';

    public function handle(CreditBudget $budget): int
    {
        $competitions = config('sports_data.sports');
        $cost = $budget->costPerCompetition();
        $perRun = $budget->perRunBudget();
        $affordable = intdiv($perRun, $cost);
        $nextReset = $budget->nextReset();
        $usage = $budget->usage();

        $summary = [
            'provider' => $budget->provider(),
            'budget_enabled' => $budget->enabled(),
            'source' => $budget->source(),
            'remaining' => $budget->remaining(),
            'used' => $budget->used(),
            'monthly_credits' => $budget->monthlyCredits(),
            'reserve' => $budget->reserve(),
            'period_start' => $budget->periodStart()->toIso8601String(),
            'next_reset' => $nextReset->toIso8601String(),
            'days_left' => (int) ceil(now()->diffInDays($nextReset)),
            'sync_cron' => $budget->cron(),
            'runs_left' => $budget->runsLeft(),
            'competitions' => $competitions,
            'cost_per_competition' => $cost,
            'cost_per_run' => count($competitions) * $cost,
            'per_run_budget' => $perRun,
            'sufficient' => $affordable >= count($competitions),
            'competitions_per_run' => min($affordable, count($competitions)),
            'usage' => $usage->map(fn ($row) => [
                'competition' => $row->competition,
                'endpoint' => $row->endpoint,
                'calls' => (int) $row->calls,
                'credits' => (int) $row->credits,
                'last_call' => $row->last_call,
            ])->all(),
            'last_call' => $budget->lastCallAt()?->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $projection = $summary['sufficient']
            ? 'suffisant'
            : "insuffisant : {$summary['competitions_per_run']} compétition(s) par synchro au lieu de ".count($competitions);

        $this->info("Fournisseur : {$summary['provider']}".($summary['budget_enabled'] ? '' : ' (budget désactivé : SPORTS_DATA_BUDGET=false)'));
        $this->table(['Indicateur', 'Valeur'], [
            ['Crédits restants', "{$summary['remaining']} (source : {$summary['source']})"],
            ['Crédits utilisés', $summary['used']],
            ['Crédits mensuels', $summary['monthly_credits']],
            ['Réserve (jamais dépensée)', $summary['reserve']],
            ['Début de période', $budget->periodStart()->toDateString()],
            ['Prochaine remise à zéro', "{$nextReset->toDateString()} (dans {$summary['days_left']} j)"],
            ['Cron de synchro', $summary['sync_cron']],
            ['Synchros restantes', $summary['runs_left']],
            ['Coût d\'une synchro complète', "{$summary['cost_per_run']} crédit(s) (".count($competitions)." compétition(s) × {$cost})"],
            ['Budget par synchro', "{$perRun} crédit(s)"],
            ['Projection', $projection],
        ]);

        $this->line('Consommation de la période :');
        $this->table(
            ['Compétition', 'Point d\'accès', 'Appels', 'Crédits', 'Dernier appel'],
            collect($summary['usage'])->map(fn ($r) => [$r['competition'] ?? '—', $r['endpoint'], $r['calls'], $r['credits'], $r['last_call']]),
        );
        $this->line('Dernier appel : '.($summary['last_call'] ?? 'aucun'));

        return self::SUCCESS;
    }
}
