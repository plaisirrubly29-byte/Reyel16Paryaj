<?php

use App\Modules\Admin\Http\Controllers\AdminResponsibleGamingController;
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

    // Jeu responsable d'un joueur (P7-02) : consultation auditée ; réouverture d'une auto-exclusion échue (motif).
    Route::get('/users/{user}/responsible-gaming', [AdminResponsibleGamingController::class, 'show'])->middleware('permission:responsible_gaming.view');
    Route::post('/users/{user}/responsible-gaming/reopen', [AdminResponsibleGamingController::class, 'reopen'])->middleware(['permission:responsible_gaming.manage', 'idempotent']);
});
