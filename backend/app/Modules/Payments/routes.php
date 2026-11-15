<?php

use App\Modules\Payments\Http\Controllers\DepositController;
use App\Modules\Payments\Http\Controllers\FakeCheckoutController;
use App\Modules\Payments\Http\Controllers\PaymentWebhookController;
use App\Modules\Payments\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
| Module : Payments Adapter (V2-B01, V2-B02, V2-B03)
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Webhooks des prestataires : sans compte, signature vérifiée par le prestataire concerné. La consultation admin est
| dans le module Admin.
*/

Route::post('/payments/webhooks/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z0-9_]+')
    ->middleware('throttle:payments-webhook');

// Dépôts du joueur connecté : ouvrir un dépôt ne crédite rien, seul le webhook vérifié du prestataire crédite.
Route::middleware(['auth:sanctum', 'active'])->prefix('payments')->group(function () {
    Route::get('/methods', [DepositController::class, 'methods']);
    Route::get('/deposits', [DepositController::class, 'index']);
    Route::post('/deposits', [DepositController::class, 'store'])->middleware(['throttle:payments-deposit', 'idempotent']);
    Route::get('/deposits/{reference}', [DepositController::class, 'show']);

    // Retraits (V2-B03) : la demande réserve le montant, le versement part ensuite chez le prestataire.
    Route::get('/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware(['throttle:payments-withdrawal', 'idempotent']);
    Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'show']);
    Route::post('/withdrawals/{reference}/cancel', [WithdrawalController::class, 'cancel'])->middleware('idempotent');
});

// Page du prestataire simulé (404 si le faux prestataire n'est pas activé ; jamais en production).
Route::prefix('payments/fake/checkout')->middleware('throttle:payments-webhook')->group(function () {
    Route::get('/{reference}', [FakeCheckoutController::class, 'show'])->where('reference', 'pay_[a-z0-9]+');
    Route::post('/{reference}', [FakeCheckoutController::class, 'decide'])->where('reference', 'pay_[a-z0-9]+');
});
