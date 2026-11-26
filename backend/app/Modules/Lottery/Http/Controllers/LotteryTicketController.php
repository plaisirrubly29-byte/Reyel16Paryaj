<?php

namespace App\Modules\Lottery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Lottery\Http\Resources\LotteryTicketResource;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Lottery\Services\LotteryRules;
use App\Modules\Lottery\Services\LotterySalesService;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Vente des fiches de borlette et fiches du joueur (V2-D09). 404 tant que LOTTERY_ENABLED=false. */
class LotteryTicketController extends Controller
{
    /** Jeux en vente, options, mises et barème (public). Vente fermée : sales_open false et aucun jeu. */
    public function games(LotteryRules $rules): JsonResponse
    {
        $this->ensureEnabled();

        $open = $rules->salesOpen();
        $currency = (string) config('app.currency', 'HTG');
        $games = $open ? collect($rules->games())->map(fn (array $game, string $key) => [
            'key' => $key,
            'digits' => $game['digits'],
            'count' => $game['count'],
            'options' => $game['options'],
            'min_stake' => ['minor' => $game['min_stake_minor'], 'currency' => $currency],
            'max_stake' => ['minor' => $game['max_stake_minor'], 'currency' => $currency],
            'payouts' => collect($game['payouts'])->map(fn (string $multiplier, int $lot) => [
                'lot' => $lot === 0 ? null : $lot,
                'multiplier' => $multiplier,
            ])->values()->all(),
        ])->values()->all() : [];

        return response()->json(['data' => [
            'sales_open' => $open,
            'close_before_minutes' => $rules->closeBeforeMinutes(),
            'max_plays_per_ticket' => $rules->maxPlaysPerTicket(),
            'games' => $games,
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['awaiting', 'pending', 'won', 'lost', 'void', 'rejected'])],
        ]);

        $tickets = LotteryTicket::where('user_id', $request->user()->id)
            ->with('draw', 'plays')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return LotteryTicketResource::collection($tickets);
    }

    public function store(Request $request, LotterySalesService $sales): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'draw' => ['required', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'plays' => ['required', 'array', 'min:1'],
            'plays.*.game' => ['required', 'string', Rule::in(LotteryRules::GAMES)],
            'plays.*.option' => ['nullable', 'integer', 'min:1', 'max:3'],
            'plays.*.numbers' => ['required', 'array', 'min:1', 'max:2'],
            'plays.*.numbers.*' => ['required', 'string', 'regex:/^[0-9]{2,5}$/'],
            'plays.*.stake' => ['required', 'array'],
            'plays.*.stake.minor' => ['required', 'integer', 'min:1'],
            'plays.*.stake.currency' => ['nullable', 'string', 'size:3'],
        ]);

        $key = strtolower((string) $request->header(RequireIdempotencyKey::HEADER));
        $ticket = $sales->place($request->user(), $data['draw'], $data['date'] ?? null, array_values($data['plays']), $key);

        // Rejeu d'une fiche déjà acceptée avec la même clé : 200 ; nouvelle fiche : 201.
        return (new LotteryTicketResource($ticket))->response()->setStatusCode($ticket->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $id): LotteryTicketResource
    {
        $this->ensureEnabled();

        $ticket = LotteryTicket::where('user_id', $request->user()->id)->with('draw', 'plays')->findOrFail($id);

        return new LotteryTicketResource($ticket);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('lottery.enabled'), 404);
    }
}
