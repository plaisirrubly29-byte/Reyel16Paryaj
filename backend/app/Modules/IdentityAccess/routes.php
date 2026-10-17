<?php

use App\Modules\IdentityAccess\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Module : Identity & Access
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Authentification Sanctum en mode SPA (cookie) : appeler GET /sanctum/csrf-cookie avant POST /auth/*.
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
