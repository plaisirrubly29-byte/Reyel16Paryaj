<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\ExposureService;
use App\Modules\Admin\Services\SystemStatusService;
use App\Modules\Admin\Support\UserSearch;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Supervision en lecture seule : état technique, exposition aux risques, recherche globale. */
class AdminSupervisionController extends Controller
{
    private const SEARCH_LIMIT = 5;

    public function system(SystemStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->status()]);
    }

    public function exposure(ExposureService $exposure): JsonResponse
    {
        return response()->json(['data' => $exposure->byEvent()]);
    }

    /**
     * Recherche de la barre supérieure : comptes, tickets et matchs, chacun seulement
     * si le rôle peut consulter ce type d'objet. Données minimales (pas de données personnelles).
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $user = $request->user();
        $results = [];

        if ($user->hasPermission('users.view')) {
            $results['users'] = UserSearch::apply(User::query(), $q)
                ->orderByDesc('id')
                ->limit(self::SEARCH_LIMIT)
                ->get(['id', 'name', 'status'])
                ->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name, 'status' => $u->status->value])
                ->all();
        }

        if ($user->hasPermission('tickets.view')) {
            // Référence dictée : casse et espaces ignorés ; un début de référence suffit.
            $reference = mb_strtoupper(preg_replace('/\s+/', '', $q));
            $results['tickets'] = BetTicket::query()
                ->where('reference', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $reference).'%')
                ->orderByDesc('id')
                ->limit(self::SEARCH_LIMIT)
                ->get(['id', 'reference', 'status'])
                ->map(fn (BetTicket $t) => ['id' => (string) $t->id, 'reference' => $t->reference, 'status' => $t->status->value])
                ->all();
        }

        if ($user->hasPermission('events.view')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
            $results['events'] = Event::query()
                ->with(['homeTeam:id,name', 'awayTeam:id,name'])
                ->where(fn ($query) => $query
                    ->whereHas('homeTeam', fn ($t) => $t->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]))
                    ->orWhereHas('awayTeam', fn ($t) => $t->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])))
                ->orderByDesc('starts_at')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (Event $e) => [
                    'id' => (string) $e->id,
                    'name' => "{$e->homeTeam?->name} – {$e->awayTeam?->name}",
                    'starts_at' => $e->starts_at->toIso8601String(),
                    'status' => $e->status->value,
                ])
                ->all();
        }

        return response()->json(['data' => $results]);
    }
}
