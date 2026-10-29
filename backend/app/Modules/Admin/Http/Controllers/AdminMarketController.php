<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminMarketResource;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\MarketAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Marchés vus par l'administration (P6-02) : consulter (events.view), suspendre ou rouvrir (markets.suspend). */
class AdminMarketController extends Controller
{
    public function show(Market $market): AdminMarketResource
    {
        return new AdminMarketResource($this->withRelations($market));
    }

    public function update(Request $request, Market $market, MarketAvailabilityService $availability): AdminMarketResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([MarketStatus::Suspended->value, MarketStatus::Open->value])],
            // 255 : colonne markets.suspension_reason. Le préfixe « auto: » est réservé aux suspensions
            // automatiques, que la synchronisation lève d'elle-même.
            'reason' => ['required', 'string', 'min:5', 'max:255', 'not_regex:/^\s*'.preg_quote(CatalogueSyncService::AUTO_SUSPENSION_PREFIX, '/').'/i'],
        ]);

        $market = $data['status'] === MarketStatus::Suspended->value
            ? $availability->suspend($request->user(), $market, trim($data['reason']))
            : $availability->reopen($request->user(), $market, trim($data['reason']));

        return new AdminMarketResource($this->withRelations($market));
    }

    private function withRelations(Market $market): Market
    {
        return $market->load(['event.competition.sport', 'selections' => fn ($q) => $q->orderBy('id')]);
    }
}
