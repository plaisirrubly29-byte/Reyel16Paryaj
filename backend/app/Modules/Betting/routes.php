<?php

use App\Modules\Betting\Http\Controllers\BetController;
use Illuminate\Support\Facades\Route;

/*
| Module : Bet Slip & Tickets
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Tickets du joueur connecté uniquement (P4-04) ; placement d'un pari avec clé d'idempotence (P4-03).
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('bets')->group(function () {
    Route::get('/', [BetController::class, 'index']);
    Route::post('/', [BetController::class, 'store'])->middleware('idempotent');
    Route::get('/{id}', [BetController::class, 'show'])->whereNumber('id');
});
