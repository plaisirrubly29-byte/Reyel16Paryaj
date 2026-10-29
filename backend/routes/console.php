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

// Import des résultats des matchs terminés (P5-04), seulement pour les compétitions qui en attendent.
Schedule::command('settlement:import-results')
    ->cron((string) config('sports_data.results_cron'))
    ->withoutOverlapping()
    ->onOneServer();

// Rattrapage du règlement (P5-05) : tickets restés en attente alors que leurs matchs ont un résultat
// (job en échec, règlement interrompu). Idempotent : ne paie jamais deux fois.
Schedule::command('settlement:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
