<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Betting\Services\LiveAcceptanceService;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\LiveOddsModel;
use App\Modules\SportsData\Services\LiveOddsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /live/odds?event_ids=1,2,3 — score, minute et cotes du moment des matchs en cours demandés (D-20), relus toutes
 * les quelques secondes par l'onglet Live, la page du match et le coupon. Seulement le 1X2, le plus/moins et le score
 * exact final (marchés proposés en direct, le score exact limité aux scores encore possibles) ; cotes calculées à la demande par le modèle (LiveOddsService), jamais mises en cache.
 * Un match qui n'est plus en cours est renvoyé avec son statut et sans marché : l'écran le retire ou le fige.
 */
class LiveOddsController extends Controller
{
    private const MAX_EVENTS = 50;

    public function __invoke(Request $request, LiveOddsService $live): JsonResponse
    {
        $request->validate(['event_ids' => ['required', 'string', 'max:600', 'regex:/^\d+(,\d+)*$/']]);
        $ids = array_slice(array_values(array_unique(array_map('intval', explode(',', (string) $request->query('event_ids'))))), 0, self::MAX_EVENTS);

        $events = Event::query()
            ->whereKey($ids)
            ->with([
                'competition.sport',
                'markets' => fn ($q) => $q->displayable()->whereIn('key', LiveOddsService::MARKETS)->orderBy('id')
                    ->with(['selections' => fn ($s) => $s->orderBy('id')]),
            ])
            ->get();

        $now = now();
        $data = $events->map(function (Event $event) use ($live, $now) {
            $isLive = $event->status === EventStatus::Live;

            return [
                'id' => (string) $event->id,
                'status' => $event->status->value,
                'score' => $isLive && $event->home_score !== null && $event->away_score !== null
                    ? ['home' => $event->home_score, 'away' => $event->away_score] : null,
                'minute' => $isLive ? LiveOddsModel::minute($event->starts_at, $now) : null,
                'markets' => ! $isLive ? [] : $event->markets->map(function ($market) use ($event, $live, $now) {
                    $market->setRelation('event', $event);
                    $quote = $live->quote($market, $now);

                    return [
                        'id' => (string) $market->id,
                        'key' => $market->key,
                        'line' => $market->line,
                        'odds_version' => $market->odds_version,
                        'bettable' => $quote->open,
                        'suspended_reason' => $quote->open ? null : $quote->reason,
                        'selections' => $live->visibleSelections($market, $quote, $market->selections)->map(fn ($s) => [
                            'id' => (string) $s->id,
                            'key' => $s->key,
                            'label' => MarketResource::selectionLabel($s->key, $market->line),
                            'name' => $s->name,
                            'odds' => MarketResource::odds($quote->odds[$s->key] ?? (string) $s->odds),
                        ])->values(),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            // Le coupon montre ce délai après « Parier » sur un match en cours.
            'live_betting' => [
                'enabled' => (bool) config('betting.allow_live'),
                'acceptance_delay_seconds' => LiveAcceptanceService::delaySeconds(),
                'max_stake_minor' => (int) config('betting.live_max_stake_minor'),
                'allow_multiple' => (bool) config('betting.live_allow_multiple'),
            ],
            'server_time' => $now->toIso8601String(),
        ]);
    }
}
