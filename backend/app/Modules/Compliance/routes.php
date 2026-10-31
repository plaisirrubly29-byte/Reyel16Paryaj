<?php

use App\Modules\Compliance\Http\Controllers\ResponsibleGamingController;
use Illuminate\Support\Facades\Route;

/*
| Module : Compliance & Responsible Play
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Jeu responsable du joueur connecté (P7-02) ; actions à effet : 'idempotent'.
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('responsible-gaming')->group(function () {
    Route::get('/', [ResponsibleGamingController::class, 'show']);
    Route::put('/limits/{period}', [ResponsibleGamingController::class, 'updateLimit'])->middleware('idempotent');
    Route::post('/pause', [ResponsibleGamingController::class, 'pause'])->middleware('idempotent');
    Route::post('/self-exclusion', [ResponsibleGamingController::class, 'selfExclude'])->middleware(['idempotent', 'throttle:mfa']);
});
