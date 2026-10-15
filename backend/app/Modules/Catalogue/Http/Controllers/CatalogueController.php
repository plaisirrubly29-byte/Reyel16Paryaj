<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Http\Resources\EventResource;
use App\Modules\Catalogue\Services\CatalogueQuery;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Sport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Catalogue public (sans authentification) : ne montre que ce qui est réellement pariable. */
class CatalogueController extends Controller
{
    public function __construct(private readonly CatalogueQuery $catalogue) {}

    /** Sports ayant des matchs ouverts, avec leurs compétitions et le nombre de matchs. */
    public function sports(): JsonResponse
    {
        $counts = $this->catalogue->visibleEvents()
            ->selectRaw('competition_id, COUNT(*) as events_count')
            ->groupBy('competition_id')
            ->pluck('events_count', 'competition_id');

        $competitions = Competition::with('sport')->whereIn('id', $counts->keys())->orderBy('name')->get();

        $sports = $competitions->groupBy('sport_id')->map(function ($items) use ($counts) {
            /** @var Sport $sport */
            $sport = $items->first()->sport;

            return [
                'slug' => $sport->slug,
                'name' => $sport->name,
                'sort_order' => $sport->sort_order,
                'events_count' => $items->sum(fn ($c) => (int) $counts[$c->id]),
                'competitions' => $items->map(fn ($c) => [
                    'id' => (string) $c->id,
                    'name' => $c->name,
                    'country' => $c->country,
                    'events_count' => (int) $counts[$c->id],
                ])->values(),
            ];
        })->sortBy([['sort_order', 'asc'], ['events_count', 'desc']])->values()
            ->map(fn ($s) => collect($s)->except('sort_order')->all());

        return response()->json(['data' => $sports]);
    }

    /** Compétitions ayant des matchs ouverts (filtrable par sport). */
    public function competitions(Request $request): JsonResponse
    {
        $data = $request->validate(['sport' => ['nullable', 'string', 'max:50']]);

        $ids = $this->catalogue->visibleEvents()->distinct()->pluck('competition_id');

        $competitions = Competition::with('sport')
            ->whereIn('id', $ids)
            ->when($data['sport'] ?? null, fn ($q, $slug) => $q->whereHas('sport', fn ($s) => $s->where('slug', $slug)))
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'country' => $c->country, 'sport' => ['slug' => $c->sport->slug, 'name' => $c->sport->name]]);

        return response()->json(['data' => $competitions]);
    }

    /** Matchs à venir, triés par heure de début, avec leur marché principal (1X2). */
    public function events(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'sport' => ['nullable', 'string', 'max:50'],
            'competition' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = $this->catalogue->visibleEvents()
            ->when($data['sport'] ?? null, fn ($q, $slug) => $q->whereHas('competition.sport', fn ($s) => $s->where('slug', $slug)))
            ->when($data['competition'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('starts_at', $date))
            ->orderBy('starts_at')
            ->orderBy('id');

        $events = $this->catalogue->withDisplayRelations($query, 'h2h')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return EventResource::collection($events);
    }

    /** Détail d'un match avec tous ses marchés pariables ; 404 s'il n'est plus proposé. */
    public function markets(string $id): EventResource
    {
        $event = $this->catalogue->withDisplayRelations($this->catalogue->visibleEvents())
            ->whereKey(ctype_digit($id) ? (int) $id : 0)
            ->firstOrFail();

        return new EventResource($event);
    }

    /** Même convention que App\Support\Http\Pagination (P1-03) : 20 par défaut, 100 au maximum. */
    private function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        return $perPage === false || $perPage < 1 ? 20 : min($perPage, 100);
    }
}
