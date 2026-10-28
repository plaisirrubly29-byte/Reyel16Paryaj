<?php

use App\Modules\Admin\Http\Controllers\AdminEventController;
use App\Modules\Admin\Http\Controllers\AdminMarketController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
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

    // Événements et marchés (P6-02) : origine et fraîcheur ; suspendre / rouvrir un marché avec motif.
    Route::get('/events', [AdminEventController::class, 'index'])->middleware('permission:events.view');
    Route::get('/events/{event}', [AdminEventController::class, 'show'])->middleware('permission:events.view')->whereNumber('event');
    Route::get('/markets/{market}', [AdminMarketController::class, 'show'])->middleware('permission:events.view')->whereNumber('market');
    Route::patch('/markets/{market}', [AdminMarketController::class, 'update'])->middleware(['permission:markets.suspend', 'idempotent'])->whereNumber('market');
});
