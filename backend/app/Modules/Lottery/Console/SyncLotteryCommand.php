<?php

namespace App\Modules\Lottery\Console;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Exceptions\LotteryException;
use App\Modules\Lottery\Services\LotterySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Importe les tirages et les résultats publiés par le fournisseur. Planifiée par LOTTERY_SYNC_CRON quand
 * LOTTERY_ENABLED=true ; peut être lancée à la main (ex. --days=7 pour rattraper une semaine).
 */
class SyncLotteryCommand extends Command
{
    protected $signature = 'lottery:sync {--days= : Nombre de jours en arrière à relire (défaut : LOTTERY_RESULTS_DAYS_BACK)}';

    protected $description = 'Synchronise les tirages de borlette et leurs résultats publiés';

    public function handle(LotterySyncService $sync, LotteryProvider $provider): int
    {
        $days = $this->option('days');
        if ($days !== null && (! ctype_digit((string) $days) || (int) $days > 31)) {
            $this->error('--days doit être un entier de 0 à 31.');

            return self::INVALID;
        }

        // Les numéros du faux fournisseur serviront un jour à payer des tickets : jamais en production.
        if ($provider->name() === 'fake' && app()->isProduction()) {
            $this->error('Faux fournisseur de tirages refusé en production : configurer LOTTERY_PROVIDER.');

            return self::FAILURE;
        }

        try {
            $report = $sync->sync($days === null ? null : (int) $days);
        } catch (LotteryException $e) {
            Log::error('lottery.sync_failed', ['provider' => $provider->name(), 'reason' => $e->reason, 'error' => $e->getMessage()]);
            $this->error("Échec ({$e->reason}) : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Fournisseur : {$provider->name()} — {$report->draws} tirage(s)");
        $this->line("Résultats : {$report->created} nouveau(x), {$report->unchanged} inchangé(s), {$report->rejected} rejeté(s), {$report->conflicts} conflit(s)");

        if ($report->conflicts > 0) {
            $this->warn('Des résultats différents de ceux déjà importés ont été reçus : voir lottery.result_conflict dans les logs.');
        }

        return self::SUCCESS;
    }
}
