<?php

use App\Modules\Lottery\Http\Controllers\LotteryController;
use Illuminate\Support\Facades\Route;

/*
| Module : Lottery (borlette — tirages et résultats)
| Routes publiques chargées par routes/api.php sous le préfixe /api/v1. 404 tant que LOTTERY_ENABLED=false.
*/

Route::middleware('throttle:catalogue')->prefix('lottery')->group(function () {
    Route::get('/draws', [LotteryController::class, 'draws']);
    Route::get('/results', [LotteryController::class, 'results']);
});
