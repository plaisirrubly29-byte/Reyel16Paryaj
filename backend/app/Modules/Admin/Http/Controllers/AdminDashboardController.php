<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\ReportingPeriodRequest;
use App\Modules\Admin\Services\AlertService;
use App\Modules\Admin\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /** Vue d'ensemble : indicateurs de la période, limités aux sections que le rôle peut consulter. */
    public function show(ReportingPeriodRequest $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => $dashboard->summary($this->permissions($request), $request->period()),
        ]);
    }

    /** Points d'attention du moment (barre du haut et vue d'ensemble), filtrés par permission. */
    public function alerts(Request $request, AlertService $alerts): JsonResponse
    {
        return response()->json(['data' => $alerts->current($this->permissions($request))]);
    }

    /** @return list<string> */
    private function permissions(Request $request): array
    {
        return $request->user()->permissionSlugs()->values()->all();
    }
}
