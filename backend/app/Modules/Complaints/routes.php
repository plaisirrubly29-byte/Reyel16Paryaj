<?php

use App\Modules\Complaints\Http\Controllers\ComplaintController;
use Illuminate\Support\Facades\Route;

/*
| Module : Complaints (réclamations, V2-G04)
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Joueur connecté : ses propres réclamations seulement (404 pour celle d'un autre joueur).
| Ouverture et messages en multipart (pièces jointes), sans clé d'idempotence (contrat) : limites par heure tenues par
| ComplaintController (seuls les envois acceptés comptent) ; réouverture : 'idempotent'.
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('complaints')->group(function () {
    Route::get('/', [ComplaintController::class, 'index']);
    Route::post('/', [ComplaintController::class, 'store']);
    Route::get('/{complaint}', [ComplaintController::class, 'show'])->whereNumber('complaint');
    Route::post('/{complaint}/messages', [ComplaintController::class, 'storeMessage'])->whereNumber('complaint');
    Route::post('/{complaint}/reopen', [ComplaintController::class, 'reopen'])->middleware('idempotent')->whereNumber('complaint');
    Route::get('/{complaint}/attachments/{attachment}', [ComplaintController::class, 'attachment'])
        ->whereNumber(['complaint', 'attachment']);
});
