<?php

use App\Modules\Catalogue\Http\Controllers\CatalogueController;
use App\Modules\Catalogue\Http\Middleware\CacheCatalogueResponse;
use Illuminate\Support\Facades\Route;

/*
| Module : Betting Catalogue (P3-05)
| Routes publiques chargées par routes/api.php sous le préfixe /api/v1.
| Ne montrent que les matchs à venir dont un marché est ouvert et à jour (règle de fraîcheur).
*/

// Limite de débit d'abord (par visiteur), puis cache court des réponses (CATALOGUE_CACHE_SECONDS, P8-03).
Route::middleware(['throttle:catalogue', CacheCatalogueResponse::class])->group(function () {
    Route::get('/sports', [CatalogueController::class, 'sports']);
    Route::get('/competitions', [CatalogueController::class, 'competitions']);
    Route::get('/events', [CatalogueController::class, 'events']);
    Route::get('/events/{id}/markets', [CatalogueController::class, 'markets']);
});
