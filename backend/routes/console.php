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
