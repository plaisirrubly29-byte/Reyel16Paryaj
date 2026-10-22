<?php

namespace App\Modules\Betting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Http\Resources\BetTicketResource;
use App\Modules\Betting\Models\BetTicket;
use App\Support\Http\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Tickets du joueur connecté uniquement : toute requête part de son identifiant, jamais de l'URL.
 * Le ticket d'un autre joueur répond 404, sans révéler qu'il existe (protection IDOR).
 */
class BetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BetTicketStatus::class)],
        ]);

        $tickets = $this->ownTickets($request)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return BetTicketResource::collection($tickets);
    }

    public function show(Request $request, string $id): BetTicketResource
    {
        return new BetTicketResource($this->ownTickets($request)->findOrFail($id));
    }

    /** @return Builder<BetTicket> */
    private function ownTickets(Request $request): Builder
    {
        return BetTicket::query()
            ->where('user_id', $request->user()->id)
            ->with('selections.event.competition.sport');
    }
}
