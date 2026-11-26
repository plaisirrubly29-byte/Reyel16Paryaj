<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminEventResource;
use App\Modules\Admin\Services\AlertService;
use App\Modules\Admin\Services\EventManagementService;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Événements vus par l'administration (P6-02) : tous statuts, origine et fraîcheur (events.view) ; modifier un match
 * (events.update) ; suspendre ou rouvrir tous ses marchés (markets.suspend).
 */
class AdminEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(EventStatus::class)],
            'competition' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'market_status' => ['nullable', Rule::enum(MarketStatus::class)],
            // Matchs sans résultat dont des sélections attendent depuis plus de 6 h après le début (alerte selections_stuck).
            'stuck' => ['nullable', 'boolean'],
        ]);

        $events = Event::query()
            ->with(['competition.sport', 'homeTeam', 'awayTeam', 'markets'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';
                $team = fn ($t) => $t->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]);
                $query->where(fn ($q) => $q
                    ->whereHas('homeTeam', $team)
                    ->orWhereHas('awayTeam', $team)
                    ->orWhere('external_id', $search)
                    ->when(ctype_digit($search), fn ($q) => $q->orWhere('id', (int) $search)));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['competition'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereBetween('starts_at', [
                Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(),
            ]))
            ->when($filters['market_status'] ?? null, fn ($q, $status) => $q->whereHas('markets', fn ($m) => $m->where('status', $status)))
            ->when((bool) ($filters['stuck'] ?? false), fn ($q) => $q
                ->whereIn('status', [EventStatus::Scheduled, EventStatus::Live, EventStatus::Postponed])
                ->whereIn('id', BetSelection::where('status', BetSelectionStatus::Pending)
                    ->where('event_starts_at', '<', now()->subHours(AlertService::SETTLEMENT_DELAY_HOURS))->select('event_id')))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminEventResource::collection($events);
    }

    public function show(Event $event): AdminEventResource
    {
        $event->load(['competition.sport', 'homeTeam', 'awayTeam', 'markets' => fn ($q) => $q->orderBy('id'), 'markets.selections']);

        return (new AdminEventResource($event))->withMarkets();
    }

    /**
     * Modifier un match (events.update, motif) : heure, report, annulation (D-05), masquage. L'annulation d'un match
     * commencé crée une demande à valider : sa référence est renvoyée dans meta.result_correction_id.
     */
    public function update(Request $request, Event $event, EventManagementService $management): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['sometimes', 'date', 'after:-7 days', 'before:+90 days'],
            'status' => ['sometimes', Rule::in([EventStatus::Scheduled->value, EventStatus::Postponed->value, EventStatus::Cancelled->value])],
            'hidden' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $changes = array_filter([
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            'status' => isset($data['status']) ? EventStatus::from($data['status']) : null,
            'hidden' => array_key_exists('hidden', $data) ? (bool) $data['hidden'] : null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            throw ValidationException::withMessages(['status' => __('Indiquez au moins un changement : heure, statut ou visibilité.')]);
        }

        ['event' => $updated, 'correction' => $correction] = $management->update($request->user(), $event, $changes, trim($data['reason']));

        return $this->show($updated)->additional(['meta' => ['result_correction_id' => $correction === null ? null : (string) $correction->id]])
            ->response();
    }

    /** Suspendre tous les marchés ouverts d'un match (markets.suspend, motif). */
    public function suspend(Request $request, Event $event, EventManagementService $management): JsonResponse
    {
        $count = $management->suspend($request->user(), $event, $this->marketReason($request));

        return $this->show($event)->additional(['meta' => ['markets_changed' => $count]])->response();
    }

    /** Rouvrir les marchés d'un match suspendus par l'administration (markets.suspend, motif). */
    public function reopen(Request $request, Event $event, EventManagementService $management): JsonResponse
    {
        $count = $management->reopen($request->user(), $event, $this->marketReason($request));

        return $this->show($event)->additional(['meta' => ['markets_changed' => $count]])->response();
    }

    /** Motif d'une suspension de marché : 255 caractères (markets.suspension_reason), préfixe « auto: » réservé. */
    private function marketReason(Request $request): string
    {
        return trim($request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255', 'not_regex:/^\s*'.preg_quote(CatalogueSyncService::AUTO_SUSPENSION_PREFIX, '/').'/i'],
        ])['reason']);
    }
}
