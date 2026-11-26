<?php

use App\Modules\Lottery\Http\Controllers\AdminLotteryController;
use App\Modules\Lottery\Http\Controllers\LotteryController;
use App\Modules\Lottery\Http\Controllers\LotteryTicketController;
use Illuminate\Support\Facades\Route;

/*
| Module : Lottery (borlette)
| Routes chargées par routes/api.php sous le préfixe /api/v1. 404 tant que LOTTERY_ENABLED=false.
| Étape 1 : tirages et résultats (publics). Étape 2 (V2-D09) : jeux, vente des fiches, fiches du joueur, administration.
*/

Route::middleware('throttle:catalogue')->prefix('lottery')->group(function () {
    Route::get('/draws', [LotteryController::class, 'draws']);
    Route::get('/results', [LotteryController::class, 'results']);
    Route::get('/games', [LotteryTicketController::class, 'games']);
});

Route::middleware(['auth:sanctum', 'active'])->prefix('lottery/tickets')->group(function () {
    Route::get('/', [LotteryTicketController::class, 'index']);
    Route::post('/', [LotteryTicketController::class, 'store'])->middleware(['throttle:bets', 'idempotent']);
    Route::get('/{id}', [LotteryTicketController::class, 'show'])->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'active', 'mfa'])->prefix('admin/lottery')->group(function () {
    Route::get('/tickets', [AdminLotteryController::class, 'tickets'])->middleware('permission:tickets.view');
    Route::get('/exposure', [AdminLotteryController::class, 'exposure'])->middleware('permission:tickets.view');
    Route::get('/blocked-numbers', [AdminLotteryController::class, 'blockedNumbers'])->middleware('permission:tickets.view');
    Route::post('/blocked-numbers', [AdminLotteryController::class, 'block'])->middleware(['permission:markets.suspend', 'idempotent']);
    Route::delete('/blocked-numbers/{id}', [AdminLotteryController::class, 'unblock'])->middleware(['permission:markets.suspend', 'idempotent'])->whereNumber('id');
});
