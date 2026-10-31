<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminEventResource;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Événements vus par l'administration (P6-02) : tous statuts, origine et fraîcheur. Permission events.view. */
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
}
