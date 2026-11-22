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
        // Mêmes matchs que la liste (1X2 affichable) : les compteurs correspondent à ce qui est listé.
        $counts = $this->catalogue->visibleEvents('h2h')
            ->selectRaw('competition_id, COUNT(*) as events_count')
            ->groupBy('competition_id')
            ->pluck('events_count', 'competition_id');

        // Rangées par pays (ou zone : Europe, Monde…) : d'abord les pays des « top compétitions »
        // (config sports_data.featured_competitions) dans cet ordre, puis les autres par nom ; dans un pays,
        // 1re division → 2e → 3e → coupes (sports_data.competition_labels.*.tier), puis l'ordre « top » et le nom.
        $featured = array_flip((array) config('sports_data.featured_competitions', []));
        $rank = fn (Competition $c): int => $featured[$c->external_key] ?? PHP_INT_MAX;
        $all = Competition::with('sport')->whereIn('id', $counts->keys())->get();
        $countryRank = $all->groupBy(fn ($c) => $c->sport_id.'|'.$c->country)->map(fn ($items) => $items->min($rank));
        $countryOf = fn (Competition $c): int => $countryRank[$c->sport_id.'|'.$c->country];
        $competitions = $all->sortBy([
            fn ($a, $b) => $countryOf($a) <=> $countryOf($b),
            fn ($a, $b) => ($a->country === null) <=> ($b->country === null),
            fn ($a, $b) => strnatcasecmp((string) $a->country, (string) $b->country),
            fn ($a, $b) => self::tierOrder($a) <=> self::tierOrder($b),
            fn ($a, $b) => $rank($a) <=> $rank($b),
            fn ($a, $b) => strnatcasecmp($a->name, $b->name),
        ])->values();

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
                    'tier' => self::tier($c),
                    'events_count' => (int) $counts[$c->id],
                ])->values(),
            ];
        })->sortBy([['sort_order', 'asc'], ['events_count', 'desc']])->values()
            ->map(fn ($s) => collect($s)->except('sort_order')->all());

        return response()->json(['data' => $sports]);
    }

    /** Ordre des niveaux dans un pays ; une compétition sans niveau (féminine, internationale…) vient après. */
    private const TIERS = ['d1', 'd2', 'd3', 'd4', 'cup'];

    /** Niveau de la compétition dans son pays (d1…d4, cup), ou null s'il n'est pas configuré. */
    private static function tier(Competition $competition): ?string
    {
        $tier = config('sports_data.competition_labels')[$competition->external_key]['tier'] ?? null;

        return in_array($tier, self::TIERS, true) ? $tier : null;
    }

    private static function tierOrder(Competition $competition): int
    {
        $index = array_search(self::tier($competition), self::TIERS, true);

        return $index === false ? count(self::TIERS) : $index;
    }

    /** Compétitions ayant des matchs ouverts (filtrable par sport). */
    public function competitions(Request $request): JsonResponse
    {
        $data = $request->validate(['sport' => ['nullable', 'string', 'max:50']]);

        $ids = $this->catalogue->visibleEvents('h2h')->distinct()->pluck('competition_id');

        $competitions = Competition::with('sport')
            ->whereIn('id', $ids)
            ->when($data['sport'] ?? null, fn ($q, $slug) => $q->whereHas('sport', fn ($s) => $s->where('slug', $slug)))
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'country' => $c->country, 'sport' => ['slug' => $c->sport->slug, 'name' => $c->sport->name]]);

        return response()->json(['data' => $competitions]);
    }

    /**
     * Matchs à venir, triés par heure de début, avec un seul marché affiché : le 1X2 par défaut, ou celui demandé
     * (?market=double_chance|totals|btts). Un match qui n'a pas ce marché est listé sans cotes (markets vide).
     */
    public function events(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'sport' => ['nullable', 'string', 'max:50'],
            'competition' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'market' => ['nullable', 'in:h2h,double_chance,totals,btts'],
        ]);

        // Les matchs listés sont ceux qui ont un 1X2 affichable, quel que soit le marché affiché :
        // la liste et les compteurs de GET /sports restent identiques d'un marché à l'autre.
        $query = $this->catalogue->visibleEvents('h2h')
            ->when($data['sport'] ?? null, fn ($q, $slug) => $q->whereHas('competition.sport', fn ($s) => $s->where('slug', $slug)))
            ->when($data['competition'] ?? null, fn ($q, $id) => $q->where('competition_id', $id))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('starts_at', $date))
            ->orderBy('starts_at')
            ->orderBy('id');

        $events = $this->catalogue->withDisplayRelations($query, $data['market'] ?? 'h2h')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return EventResource::collection($events);
    }

    /** Détail d'un match avec tous ses marchés affichables (1X2 puis plus/moins) ; 404 s'il n'est plus proposé. */
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
