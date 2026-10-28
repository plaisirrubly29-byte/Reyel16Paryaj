<?php

use App\Modules\Wallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
| Module : Wallet & Ledger
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Lecture seule : aucune route ne modifie un solde (garde-fou : LedgerSchemaTest).
*/

Route::middleware(['auth:sanctum', 'active'])->prefix('wallet')->group(function () {
    Route::get('/', [WalletController::class, 'show']);
    Route::get('/transactions', [WalletController::class, 'transactions']);
});
