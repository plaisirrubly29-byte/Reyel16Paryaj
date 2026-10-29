<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /** Vue d'ensemble : indicateurs limités aux sections que le rôle peut consulter. */
    public function show(Request $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary($request->user()->permissionSlugs()->values()->all())]);
    }
}
