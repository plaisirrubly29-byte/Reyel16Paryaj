<?php

use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminEventController;
use App\Modules\Admin\Http\Controllers\AdminKycController;
use App\Modules\Admin\Http\Controllers\AdminLedgerController;
use App\Modules\Admin\Http\Controllers\AdminMarketController;
use App\Modules\Admin\Http\Controllers\AdminPaymentController;
use App\Modules\Admin\Http\Controllers\AdminResponsibleGamingController;
use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Admin\Http\Controllers\AdminSupervisionController;
use App\Modules\Admin\Http\Controllers\AdminTicketController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use App\Modules\Admin\Http\Controllers\AdminWalletController;
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
    Route::put('/users/{user}/stake-limit', [AdminUserController::class, 'stakeLimit'])->middleware(['permission:users.suspend', 'idempotent']);

    // Solde d'un joueur : consultation (ledger.view) ; dépôt / retrait reçu hors plateforme, réservé au Super Admin (motif).
    Route::get('/users/{user}/wallet', [AdminWalletController::class, 'show'])->middleware('permission:ledger.view');
    Route::post('/users/{user}/wallet/adjustments', [AdminWalletController::class, 'store'])->middleware(['permission:wallet.adjust', 'idempotent']);

    // Jeu responsable d'un joueur (P7-02) : consultation auditée ; réouverture d'une auto-exclusion échue (motif).
    Route::get('/users/{user}/responsible-gaming', [AdminResponsibleGamingController::class, 'show'])->middleware('permission:responsible_gaming.view');
    Route::post('/users/{user}/responsible-gaming/reopen', [AdminResponsibleGamingController::class, 'reopen'])->middleware(['permission:responsible_gaming.manage', 'idempotent']);

    // KYC (V2-C03) : file des dossiers, pièces déchiffrées (auditées), validation ou refus avec motif.
    Route::get('/kyc', [AdminKycController::class, 'index'])->middleware('permission:kyc.view');
    Route::get('/kyc/{submission}', [AdminKycController::class, 'show'])->middleware('permission:kyc.view')->whereNumber('submission');
    Route::get('/kyc/{submission}/files/{file}', [AdminKycController::class, 'file'])->middleware('permission:kyc.view')->whereNumber(['submission', 'file']);
    Route::post('/kyc/{submission}/approve', [AdminKycController::class, 'approve'])->middleware(['permission:kyc.approve', 'idempotent'])->whereNumber('submission');
    Route::post('/kyc/{submission}/reject', [AdminKycController::class, 'reject'])->middleware(['permission:kyc.reject', 'idempotent'])->whereNumber('submission');

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
    // Journal des transactions des prestataires de paiement et des webhooks reçus (V2-B01).
    Route::get('/payments', [AdminPaymentController::class, 'index'])->middleware('permission:ledger.view');
    Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->whereNumber('payment')->middleware('permission:ledger.view');
    Route::get('/finance/corrections', [FinanceCorrectionController::class, 'index'])->middleware('permission:ledger.view');
    Route::get('/finance/corrections/{correction}', [FinanceCorrectionController::class, 'show'])->whereNumber('correction')->middleware('permission:ledger.view');
    Route::post('/finance/corrections', [FinanceCorrectionController::class, 'store'])->middleware(['permission:finance.correct', 'idempotent']);
    Route::post('/finance/corrections/{correction}/approve', [FinanceCorrectionController::class, 'approve'])
        ->whereNumber('correction')->middleware(['permission:finance.approve', 'idempotent']);
    Route::post('/finance/corrections/{correction}/reject', [FinanceCorrectionController::class, 'reject'])
        ->whereNumber('correction')->middleware(['permission:finance.approve', 'idempotent']);

    // Paramètres de la plateforme versionnés (P6-08)
    Route::get('/settings', [AdminSettingsController::class, 'show'])->middleware('permission:settings.view');
    Route::get('/settings/versions', [AdminSettingsController::class, 'versions'])->middleware('permission:settings.view');
    Route::put('/settings', [AdminSettingsController::class, 'update'])->middleware(['permission:settings.update', 'idempotent']);
});
