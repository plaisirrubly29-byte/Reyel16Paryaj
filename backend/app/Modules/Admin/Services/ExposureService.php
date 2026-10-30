<?php

namespace App\Modules\Admin\Services;

use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Exposition de la maison sur les tickets EN COURS, par match.
 *
 * Paris simples : sur un marché, une seule issue gagne. Le pire cas d'un marché est donc
 * « gains de l'issue la plus chère − mises encaissées sur ce marché » (négatif = la maison gagne
 * quelle que soit l'issue). On n'additionne jamais les gains de toutes les issues.
 * Le pire cas d'un match additionne celui de ses marchés : c'est un MAJORANT (les marchés d'un
 * même match sont liés, ils ne peuvent pas tous tomber sur leur pire issue).
 *
 * Combinés : leur gain dépend d'autres matchs ; ils sont listés à part (nombre, gains potentiels)
 * et ne sont pas intégrés au pire cas du match.
 */
class ExposureService
{
    public const MAX_EVENTS = 50;

    /** @return array{currency: string, events: list<array<string, mixed>>, total_events: int} */
    public function byEvent(): array
    {
        $rows = DB::table('bet_selections')
            ->join('bet_tickets', 'bet_tickets.id', '=', 'bet_selections.bet_ticket_id')
            ->where('bet_tickets.status', BetTicketStatus::Pending->value)
            ->groupBy('bet_selections.event_id', 'bet_selections.market_key', 'bet_selections.market_line', 'bet_selections.selection_id', 'bet_tickets.type')
            ->select(['bet_selections.event_id', 'bet_selections.market_key', 'bet_selections.market_line', 'bet_selections.selection_id', 'bet_tickets.type'])
            ->selectRaw('MAX(bet_selections.event_name) AS event_name, MAX(bet_selections.event_starts_at) AS starts_at, MAX(bet_selections.selection_name) AS selection_name')
            ->selectRaw('COUNT(*) AS tickets, SUM(bet_tickets.stake_minor) AS stakes_minor, SUM(bet_tickets.potential_payout_minor) AS potential_minor')
            ->get();

        $events = $rows->groupBy('event_id')->map(function ($eventRows, $eventId) {
            $singles = $eventRows->where('type', BetType::Single->value);
            $multiples = $eventRows->where('type', '!=', BetType::Single->value);

            $markets = $singles
                ->groupBy(fn ($row) => $row->market_key.'|'.$row->market_line)
                ->map(function ($marketRows) {
                    $stakes = (int) $marketRows->sum('stakes_minor');
                    $selections = $marketRows
                        ->map(fn ($row) => [
                            'selection_id' => (string) $row->selection_id,
                            'name' => (string) $row->selection_name,
                            'tickets' => (int) $row->tickets,
                            'stakes_minor' => (int) $row->stakes_minor,
                            'potential_payout_minor' => (int) $row->potential_minor,
                        ])
                        ->sortByDesc('potential_payout_minor')
                        ->values();

                    return [
                        'market_key' => $marketRows->first()->market_key,
                        'line' => $marketRows->first()->market_line,
                        'stakes_minor' => $stakes,
                        'worst_case_minor' => (int) $selections->max('potential_payout_minor') - $stakes,
                        'selections' => $selections->all(),
                    ];
                })
                ->sortByDesc('worst_case_minor')
                ->values();

            $first = $eventRows->first();

            return [
                'event_id' => (string) $eventId,
                'name' => (string) $first->event_name,
                'starts_at' => CarbonImmutable::parse($eventRows->max('starts_at'), 'UTC')->toIso8601String(),
                'single_tickets' => (int) $singles->sum('tickets'),
                'single_stakes_minor' => (int) $singles->sum('stakes_minor'),
                'worst_case_minor' => (int) $markets->sum('worst_case_minor'),
                'markets' => $markets->all(),
                'multiple_tickets' => (int) $multiples->sum('tickets'),
                'multiple_potential_payout_minor' => (int) $multiples->sum('potential_minor'),
            ];
        })
            ->sortByDesc('worst_case_minor')
            ->values();

        return [
            'currency' => (string) config('wallet.currency'),
            'total_events' => $events->count(),
            'events' => $events->take(self::MAX_EVENTS)->all(),
        ];
    }
}
