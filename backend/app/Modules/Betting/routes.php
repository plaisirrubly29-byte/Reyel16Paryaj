<?php

use App\Modules\Betting\Http\Controllers\BetController;
use Illuminate\Support\Facades\Route;

/*
| Module : Bet Slip & Tickets
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Lecture (P4-04) : tickets du joueur connecté uniquement. Placement d'un pari : P4-03.
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('bets')->group(function () {
    Route::get('/', [BetController::class, 'index']);
    Route::get('/{id}', [BetController::class, 'show'])->whereNumber('id');
});
