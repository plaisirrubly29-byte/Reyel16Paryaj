<?php

use App\Modules\Admin\Http\Controllers\AdminLedgerController;
use App\Modules\Admin\Http\Controllers\AdminResponsibleGamingController;
use App\Modules\Admin\Http\Controllers\AdminTicketController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use App\Modules\Admin\Http\Controllers\FinanceCorrectionController;
use Illuminate\Support\Facades\Route;

/*
| Module : Admin & Operations
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Toute route : ['auth:sanctum', 'active', 'mfa', 'permission:…'] ; actions à effet : 'idempotent' + motif.
*/

Route::middleware(['auth:sanctum', 'active', 'mfa'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->middleware('permission:users.view');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware(['permission:users.suspend', 'idempotent']);

    // Jeu responsable d'un joueur (P7-02) : consultation auditée ; réouverture d'une auto-exclusion échue (motif).
    Route::get('/users/{user}/responsible-gaming', [AdminResponsibleGamingController::class, 'show'])->middleware('permission:responsible_gaming.view');
    Route::post('/users/{user}/responsible-gaming/reopen', [AdminResponsibleGamingController::class, 'reopen'])->middleware(['permission:responsible_gaming.manage', 'idempotent']);

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
