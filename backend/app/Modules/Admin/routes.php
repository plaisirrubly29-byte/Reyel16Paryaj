<?php

use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminEventController;
use App\Modules\Admin\Http\Controllers\AdminLedgerController;
use App\Modules\Admin\Http\Controllers\AdminMarketController;
use App\Modules\Admin\Http\Controllers\AdminResponsibleGamingController;
use App\Modules\Admin\Http\Controllers\AdminSupervisionController;
use App\Modules\Admin\Http\Controllers\AdminTicketController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use App\Modules\Admin\Http\Controllers\FinanceCorrectionController;
use App\Modules\Admin\Http\Controllers\ResultCorrectionController;
use Illuminate\Support\Facades\Route;

/*
| Module : Admin & Operations
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Toute route : ['auth:sanctum', 'active', 'mfa', 'permission:…'] ; actions à effet : 'idempotent' + motif.
*/

Route::middleware(['auth:sanctum', 'active', 'mfa'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'show'])->middleware('permission:dashboard.view');
    Route::get('/alerts', [AdminDashboardController::class, 'alerts'])->middleware('permission:dashboard.view');
    Route::get('/search', [AdminSupervisionController::class, 'search'])->middleware(['permission:dashboard.view', 'throttle:60,1']);
    // Supervision en lecture seule : technique (rôles qui consultent les paramètres), risques (rôles qui consultent les tickets).
    Route::get('/system', [AdminSupervisionController::class, 'system'])->middleware('permission:settings.view');
    Route::get('/risk/exposure', [AdminSupervisionController::class, 'exposure'])->middleware('permission:tickets.view');

    Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->middleware('permission:users.view');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware(['permission:users.suspend', 'idempotent']);

    // Jeu responsable d'un joueur (P7-02) : consultation auditée ; réouverture d'une auto-exclusion échue (motif).
    Route::get('/users/{user}/responsible-gaming', [AdminResponsibleGamingController::class, 'show'])->middleware('permission:responsible_gaming.view');
    Route::post('/users/{user}/responsible-gaming/reopen', [AdminResponsibleGamingController::class, 'reopen'])->middleware(['permission:responsible_gaming.manage', 'idempotent']);

    // Événements et marchés (P6-02) : origine et fraîcheur ; suspendre / rouvrir un marché avec motif.
    Route::get('/events', [AdminEventController::class, 'index'])->middleware('permission:events.view');
    Route::get('/events/{event}', [AdminEventController::class, 'show'])->middleware('permission:events.view')->whereNumber('event');
    Route::get('/markets/{market}', [AdminMarketController::class, 'show'])->middleware('permission:events.view')->whereNumber('market');
    Route::patch('/markets/{market}', [AdminMarketController::class, 'update'])->middleware(['permission:markets.suspend', 'idempotent'])->whereNumber('market');

    // Correction de résultat à double contrôle (P5-06) : demander (results.enter), valider (results.approve, jamais le
    // demandeur), refuser ou retirer (le service vérifie : demandeur ou results.approve) ; historique d'un match.
    Route::get('/events/{event}/results', [ResultCorrectionController::class, 'history'])->middleware('permission:events.view')->whereNumber('event');
    Route::post('/events/{event}/result-corrections', [ResultCorrectionController::class, 'store'])
        ->whereNumber('event')->middleware(['permission:results.enter', 'idempotent']);
    Route::get('/result-corrections', [ResultCorrectionController::class, 'index'])->middleware('permission:results.enter');
    Route::get('/result-corrections/{correction}', [ResultCorrectionController::class, 'show'])->whereNumber('correction')->middleware('permission:results.enter');
    Route::post('/result-corrections/{correction}/approve', [ResultCorrectionController::class, 'approve'])
        ->whereNumber('correction')->middleware(['permission:results.approve', 'idempotent']);
    Route::post('/result-corrections/{correction}/reject', [ResultCorrectionController::class, 'reject'])
        ->whereNumber('correction')->middleware(['permission:results.enter', 'idempotent']);

    // Tickets et finance (P6-04)
    Route::get('/tickets', [AdminTicketController::class, 'index'])->middleware('permission:tickets.view');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->whereNumber('ticket')->middleware('permission:tickets.view');
    Route::get('/ledger', [AdminLedgerController::class, 'index'])->middleware('permission:ledger.view');
    Route::get('/finance/reconciliation', [AdminLedgerController::class, 'reconciliation'])->middleware('permission:ledger.view');
    Route::get('/finance/corrections', [FinanceCorrectionController::class, 'index'])->middleware('permission:ledger.view');
    Route::post('/finance/corrections', [FinanceCorrectionController::class, 'store'])->middleware(['permission:finance.correct', 'idempotent']);
    Route::post('/finance/corrections/{correction}/approve', [FinanceCorrectionController::class, 'approve'])
        ->whereNumber('correction')->middleware(['permission:finance.approve', 'idempotent']);
    Route::post('/finance/corrections/{correction}/reject', [FinanceCorrectionController::class, 'reject'])
        ->whereNumber('correction')->middleware(['permission:finance.approve', 'idempotent']);
});
