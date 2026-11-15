<?php

use App\Modules\IdentityAccess\Http\Controllers\AuthController;
use App\Modules\IdentityAccess\Http\Controllers\KycController;
use App\Modules\IdentityAccess\Http\Controllers\MfaController;
use App\Modules\IdentityAccess\Http\Controllers\PasswordResetController;
use App\Modules\IdentityAccess\Http\Controllers\RoleAssignmentController;
use App\Modules\IdentityAccess\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Module : Identity & Access
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Authentification Sanctum en mode SPA (cookie) : appeler GET /sanctum/csrf-cookie avant POST /auth/*.
| Toute route /admin : ['auth:sanctum', 'active', 'mfa', 'permission:…'].
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    Route::middleware('throttle:password-reset')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('/reset-password', [PasswordResetController::class, 'reset']);
    });

    Route::middleware(['auth:sanctum', 'active'])->prefix('mfa')->group(function () {
        Route::post('/setup', [MfaController::class, 'setup']);
        Route::post('/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa');
        Route::post('/verify', [MfaController::class, 'verify'])->middleware('throttle:mfa');
        Route::post('/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware(['mfa', 'throttle:mfa']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Appareils connectés : liste, déconnexion d'un autre appareil ou de tous les autres (V2-C06).
    Route::prefix('me/sessions')->group(function () {
        Route::get('/', [SessionController::class, 'index']);
        Route::delete('/', [SessionController::class, 'destroyOthers']);
        Route::delete('/{session}', [SessionController::class, 'destroy']);
    });

    // Vérification d'identité (V2-C03) : statut et envoi d'un dossier (multipart). Pas de clé d'idempotence : un second
    // envoi est refusé tant qu'un dossier est en attente (kyc_already_pending).
    Route::get('/me/kyc', [KycController::class, 'show']);
    Route::post('/me/kyc', [KycController::class, 'store'])->middleware('throttle:kyc');

    Route::middleware(['mfa', 'permission:roles.manage', 'idempotent'])->prefix('admin/users/{user}')->group(function () {
        Route::put('/roles', [RoleAssignmentController::class, 'update']);
        Route::delete('/mfa', [MfaController::class, 'reset']);
    });
});
