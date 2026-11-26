<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\TicketVoidResource;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use App\Modules\Settlement\Models\TicketVoid;
use App\Modules\Settlement\Services\TicketVoidService;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Annulation d'un ticket à double validation (audit du 2026-11-22, A2) : demander (tickets.void), valider
 * (tickets.void_approve, jamais le demandeur), refuser ou retirer ; consulter (tickets.view).
 */
class TicketVoidController extends Controller
{
    private const RELATIONS = ['ticket', 'requester:id,name', 'decider:id,name'];

    public function __construct(private readonly TicketVoidService $voids) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TicketVoidStatus::class)],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $voids = TicketVoid::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['ticket_id'] ?? null, fn ($q, $id) => $q->where('bet_ticket_id', $id))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return TicketVoidResource::collection($voids);
    }

    public function show(TicketVoid $ticketVoid): TicketVoidResource
    {
        $resource = new TicketVoidResource($ticketVoid->load(self::RELATIONS));

        // En attente : ce que la validation changerait maintenant (statut, gain, écart au portefeuille).
        return $ticketVoid->status === TicketVoidStatus::Pending
            ? $resource->withPreview($this->voids->preview($ticketVoid))
            : $resource;
    }

    public function store(Request $request, BetTicket $ticket): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->show($this->voids->request($request->user(), $ticket, trim($data['reason'])))->response()->setStatusCode(201);
    }

    public function approve(Request $request, TicketVoid $ticketVoid): TicketVoidResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new TicketVoidResource($this->voids->approve($request->user(), $ticketVoid, trim($data['reason']))->load(self::RELATIONS));
    }

    public function reject(Request $request, TicketVoid $ticketVoid): TicketVoidResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new TicketVoidResource($this->voids->reject($request->user(), $ticketVoid, trim($data['reason']))->load(self::RELATIONS));
    }
}
