<?php

use Illuminate\Support\Facades\Route;

// Le backend est une API : l'interface joueur et l'admin sont servis par /frontend.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));
