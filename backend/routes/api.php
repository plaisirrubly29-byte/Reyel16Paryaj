<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — préfixe /api/v1 (voir bootstrap/app.php)
|--------------------------------------------------------------------------
|
| Chaque module déclare ses propres routes dans app/Modules/<Module>/routes.php.
| Ce fichier ne contient que les routes transverses.
|
*/

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'version' => 'v1',
    'time' => now()->toIso8601String(),
]))->name('api.health');

foreach (glob(app_path('Modules/*/routes.php')) as $moduleRoutes) {
    require $moduleRoutes;
}
