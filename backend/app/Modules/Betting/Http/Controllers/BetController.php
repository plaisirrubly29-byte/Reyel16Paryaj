<?php

namespace App\Modules\Betting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Http\Requests\PlaceBetRequest;
use App\Modules\Betting\Http\Resources\BetTicketResource;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Betting\Services\BetPlacementService;
use App\Support\Http\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

    /** Accepte un pari (P4-03) : 201 et le reçu immuable du ticket. */
    public function store(PlaceBetRequest $request, BetPlacementService $placement): JsonResponse
    {
        $ticket = $placement->place(
            $request->user(),
            $request->validated('selections'),
            (int) $request->validated('stake.minor'),
            $request->validated('stake.currency'),
            // Déjà validée (UUID) par le middleware "idempotent" ; figée sur le ticket contre les rejeux après panne.
            strtolower((string) $request->header(RequireIdempotencyKey::HEADER)),
        );

        return (new BetTicketResource($ticket))->response()->setStatusCode(201);
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
