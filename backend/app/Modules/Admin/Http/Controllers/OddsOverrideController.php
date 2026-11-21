<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\OddsOverrideResource;
use App\Modules\SportsData\Enums\OddsOverrideStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsOverride;
use App\Modules\SportsData\Services\OddsOverrideService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cotes fixées à la main (V2-D03, décision D-18) : consulter (events.view) ; fixer, valider, refuser ou terminer
 * (odds.override : Opérations, Super Admin), motif obligatoire.
 */
class OddsOverrideController extends Controller
{
    public function __construct(private readonly OddsOverrideService $overrides) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(OddsOverrideStatus::class)],
            'event_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $overrides = $this->query()
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['event_id']), fn ($q) => $q->whereHas('market', fn ($m) => $m->where('event_id', $data['event_id'])))
            // Les demandes en attente d'abord, puis les plus récentes.
            ->orderByRaw('case when status = ? then 0 else 1 end', [OddsOverrideStatus::Pending->value])
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return OddsOverrideResource::collection($overrides);
    }

    public function show(OddsOverride $override): OddsOverrideResource
    {
        return new OddsOverrideResource($this->query()->findOrFail($override->id));
    }

    public function store(Request $request, Market $market): JsonResponse
    {
        $data = $request->validate([
            'selection_id' => ['required', 'integer'],
            // Cote décimale en chaîne, 2 décimales au plus, de 1.01 à 1000.00.
            'odds' => ['required', 'string', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
        if (bccomp($data['odds'], '1.01', 2) < 0 || bccomp($data['odds'], '1000', 2) > 0) {
            throw ValidationException::withMessages(['odds' => __('La cote doit être comprise entre 1,01 et 1 000.')]);
        }

        $override = $this->overrides->request($request->user(), $market, (int) $data['selection_id'], $data['odds'],
            trim($data['reason']), isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null);

        return (new OddsOverrideResource($this->query()->findOrFail($override->id)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, OddsOverride $override): OddsOverrideResource
    {
        $reason = $this->reason($request);

        return new OddsOverrideResource($this->query()->findOrFail($this->overrides->approve($request->user(), $override, $reason)->id));
    }

    public function reject(Request $request, OddsOverride $override): OddsOverrideResource
    {
        $reason = $this->reason($request);

        return new OddsOverrideResource($this->query()->findOrFail($this->overrides->reject($request->user(), $override, $reason)->id));
    }

    public function end(Request $request, OddsOverride $override): OddsOverrideResource
    {
        $reason = $this->reason($request);

        return new OddsOverrideResource($this->query()->findOrFail($this->overrides->end($request->user(), $override, $reason)->id));
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason']);
    }

    /** @return Builder<OddsOverride> */
    private function query()
    {
        return OddsOverride::query()->with([
            'selection', 'requester:id,name', 'decider:id,name', 'ender:id,name', 'market.event.homeTeam', 'market.event.awayTeam',
        ]);
    }
}
