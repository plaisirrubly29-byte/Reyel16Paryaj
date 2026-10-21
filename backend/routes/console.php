<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge des clés d'idempotence expirées (App\Models\IdempotencyKey::prunable).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();

// Synchronisation des matchs et cotes (P3-03). Fréquence réglée par SPORTS_DATA_SYNC_CRON pour respecter
// le quota du fournisseur ; jamais deux synchronisations en parallèle.
Schedule::command('sports-data:sync')
    ->cron((string) config('sports_data.sync_cron'))
    ->withoutOverlapping()
    ->onOneServer();
