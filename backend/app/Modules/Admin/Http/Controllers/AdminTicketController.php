<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminTicketResource;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminTicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::enum(BetTicketStatus::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'event_id' => ['nullable', 'integer', 'min:1'],
            // Tickets dont une demande d'annulation est dans cet état (pending : à valider).
            'void_status' => ['nullable', Rule::enum(TicketVoidStatus::class)],
            'placed_from' => ['nullable', 'date_format:Y-m-d'],
            'placed_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:placed_from'],
        ]);

        $tickets = BetTicket::query()
            ->with('user:id,name')
            // Référence dictée au téléphone : casse et espaces ignorés, correspondance exacte.
            ->when($filters['reference'] ?? null, fn ($q, string $reference) => $q->where('reference', mb_strtoupper(preg_replace('/\s+/', '', $reference))))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($q, int $userId) => $q->where('user_id', $userId))
            // Tickets qui ont une sélection sur ce match (annulation, report, erreur de cote).
            ->when($filters['event_id'] ?? null, fn ($q, int $eventId) => $q->whereHas('selections', fn ($s) => $s->where('event_id', $eventId)))
            ->when($filters['void_status'] ?? null, fn ($q, string $status) => $q->whereHas('voids', fn ($v) => $v->where('status', $status)))
            ->when($filters['placed_from'] ?? null, fn ($q, string $date) => $q->where('placed_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['placed_to'] ?? null, fn ($q, string $date) => $q->where('placed_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminTicketResource::collection($tickets);
    }

    public function show(Request $request, BetTicket $ticket): AdminTicketResource
    {
        $ticket->load(['user:id,name', 'selections.event.competition.sport',
            'voids' => fn ($q) => $q->orderBy('id')->with(['requester:id,name', 'decider:id,name'])]);

        // Écritures du ticket (mise, gain, remboursement) : réservées aux rôles qui consultent le ledger.
        if ($request->user()->hasPermission('ledger.view')) {
            $ticket->setRelation('ledgerEntries', LedgerEntry::query()
                ->with(['wallet:id,user_id', 'reversedBy:id,reversal_of_id'])
                ->where('related_type', 'bet')
                ->where('related_id', (string) $ticket->id)
                ->orderBy('id')
                ->get());
        }

        return new AdminTicketResource($ticket);
    }
}
