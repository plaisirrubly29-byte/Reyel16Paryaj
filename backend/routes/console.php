<?php

use App\Models\IdempotencyKey;
use App\Modules\Admin\Services\SystemStatusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge des clés d'idempotence expirées (App\Models\IdempotencyKey::prunable).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();

// Signal de vie du planificateur, lu par la supervision admin (SystemStatusService) : si le cron
// ou schedule:work s'arrête, l'écran le montre au lieu de laisser croire que les synchros tournent.
Schedule::call(fn () => Cache::forever(SystemStatusService::HEARTBEAT_KEY, now()->toIso8601String()))
    ->name('scheduler-heartbeat')
    ->everyMinute();

// Synchronisation des matchs et cotes (P3-03). Fréquence réglée par SPORTS_DATA_SYNC_CRON pour respecter
// le quota du fournisseur ; jamais deux synchronisations en parallèle.
Schedule::command('sports-data:sync')
    ->cron((string) config('sports_data.sync_cron'))
    ->withoutOverlapping()
    ->onOneServer();

// Matchs en direct : score et cotes des matchs en cours, plafonnés en crédits par jour (SPORTS_DATA_LIVE_DAILY_CREDITS).
Schedule::command('sports-data:live')
    ->cron((string) config('sports_data.live_cron'))
    ->when(fn () => (bool) config('sports_data.live_enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Paris en direct (D-20) : score relu toutes les N secondes (SPORTS_DATA_LIVE_SCORES_SECONDS : 10, 15, 20, 30 ou 60),
// seulement quand les paris en direct sont ouverts — aucun crédit dépensé tant que BETTING_ALLOW_LIVE est coupé.
$scores = Schedule::command('sports-data:live-scores')
    ->when(fn () => (bool) config('sports_data.live_enabled') && (bool) config('betting.allow_live'))
    ->withoutOverlapping()
    ->onOneServer();
match ((int) config('sports_data.live_model.scores_interval_seconds')) {
    10 => $scores->everyTenSeconds(),
    15 => $scores->everyFifteenSeconds(),
    20 => $scores->everyTwentySeconds(),
    60 => $scores->everyMinute(),
    default => $scores->everyThirtySeconds(),
};

// Paris en direct en attente d'acceptation (D-20) : revérifiés à la fin du délai, refusés (mise rendue) passé
// betting.live_acceptance_timeout_seconds. Filet de sécurité de la tâche en file ; tourne même live coupé, pour refuser.
Schedule::command('betting:live-acceptance')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->onOneServer();

// Marchés supplémentaires des matchs de football proches (double chance, handicap, buts par équipe…), match par match,
// plafonnés en crédits par jour (SPORTS_DATA_EXTRA_DAILY_CREDITS). Désactivé par défaut (SPORTS_DATA_EXTRA).
Schedule::command('sports-data:extra-markets')
    ->cron((string) config('sports_data.extra_markets_cron'))
    ->when(fn () => (bool) config('sports_data.extra_markets_enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Dépôts jamais confirmés par le prestataire dans le délai : « expired » (V2-B01).
Schedule::command('payments:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Statut relu chez le prestataire pour les dépôts en attente : le webhook Plop Plop n'est envoyé qu'une fois (V2-B02).
Schedule::command('payments:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Versement des retraits en attente et vérification de ceux en cours (V2-B03) ; sans effet s'ils sont désactivés.
Schedule::command('payments:payouts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Cotes fixées à la main arrivées à leur date limite ou au début du match : retour à la cote calculée (V2-D03, D-18).
Schedule::command('odds:expire-overrides')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Import des résultats des matchs terminés (P5-04), seulement pour les compétitions qui en attendent.
Schedule::command('settlement:import-results')
    ->cron((string) config('sports_data.results_cron'))
    ->withoutOverlapping()
    ->onOneServer();

// Statistiques des matchs terminés (V2-D09 : mi-temps, corners, cartons), seulement pour ceux qui ont des paris en attente.
Schedule::command('settlement:import-statistics')
    ->cron((string) config('sports_data.statistics.cron'))
    ->when(fn () => (string) config('sports_data.statistics.provider') !== '')
    ->withoutOverlapping()
    ->onOneServer();

// Rattrapage du règlement (P5-05) : tickets restés en attente alors que leurs matchs ont un résultat
// (job en échec, règlement interrompu). Idempotent : ne paie jamais deux fois.
Schedule::command('settlement:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Monitoring de production (P9-02) : alertes poussées à l'astreinte (Telegram, e-mail) et signal de vie externe.
Schedule::command('monitoring:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Tirages de borlette et résultats publiés (module Lottery), seulement quand LOTTERY_ENABLED=true.
Schedule::command('lottery:sync')
    ->cron((string) config('lottery.sync_cron'))
    ->when(fn () => (bool) config('lottery.enabled'))
    ->withoutOverlapping()
    ->onOneServer();
