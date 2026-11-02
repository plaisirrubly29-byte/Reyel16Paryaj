<?php

namespace App\Modules\Betting\Services;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Models\Event;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plafonds d'engagement (V2-D05) : perte maximale de la maison sur un match et sur un marché.
 *
 * Pire cas d'un marché, comme ExposureService : « gains de l'issue la plus chère − mises des paris simples sur ce
 * marché » ; celui d'un match additionne ses marchés (majorant). Un combiné compte sur une issue, pour son gain
 * potentiel entier, seulement quand toutes ses AUTRES sélections sont déjà gagnées (ou annulées) : tant qu'un
 * autre match reste à jouer, il ne peut pas coûter sur celui-ci seul.
 *
 * Contrôle fait dans la transaction du pari, ticket déjà inséré : refus si un pire cas dépasse son plafond ET
 * augmente avec ce ticket (un pari qui réduit le risque reste accepté après une baisse du plafond).
 *
 * Concurrence : les paris d'un même match ne s'attendent pas (verrou partagé sur le marché, test de charge P8-03).
 * Au-delà de la moitié d'un plafond, le match est verrouillé (FOR NO KEY UPDATE) et le calcul refait : les paris
 * proches du plafond passent un par un. Deux paris simultanés sous la moitié ne peuvent le dépasser ensemble
 * que si leurs gains cumulés dépassent la moitié restante.
 */
class ExposureGuard
{
    /** @throws ApiException risk_limit_exceeded */
    public function assertWithinLimits(BetTicket $ticket): void
    {
        $eventIds = $ticket->selections->pluck('event_id')->unique()->sort()->values()->all();
        $marketIds = $ticket->selections->pluck('market_id')->all();

        $exposure = $this->exposure($eventIds, $ticket->id);
        if ($this->nearLimit($exposure, $marketIds)) {
            Event::whereKey($eventIds)->orderBy('id')->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->get(['id']);
            $exposure = $this->exposure($eventIds, $ticket->id);
        }

        $marketLimit = (int) config('betting.max_market_liability_minor');
        foreach ($marketIds as $marketId) {
            $market = $exposure['markets'][$marketId] ?? null;
            if ($market !== null && $market['after'] > $marketLimit && $market['after'] > $market['before']) {
                $this->refuse('market', $market['event_id']);
            }
        }

        $eventLimit = (int) config('betting.max_event_liability_minor');
        foreach ($eventIds as $eventId) {
            $event = $exposure['events'][$eventId] ?? null;
            if ($event !== null && $event['after'] > $eventLimit && $event['after'] > $event['before']) {
                $this->refuse('event', $eventId);
            }
        }
    }

    /**
     * Pire cas de chaque marché et match concernés, sans le nouveau ticket (before) et avec (after).
     *
     * @param  list<int>  $eventIds
     * @return array{markets: array<int, array{event_id: int, before: int, after: int}>, events: array<int, array{before: int, after: int}>}
     */
    public function exposure(array $eventIds, ?int $newTicketId = null): array
    {
        $settled = [BetSelectionStatus::Won->value, BetSelectionStatus::Void->value];

        $rows = DB::table('bet_selections')
            ->join('bet_tickets', 'bet_tickets.id', '=', 'bet_selections.bet_ticket_id')
            ->whereIn('bet_selections.event_id', $eventIds)
            ->where('bet_selections.status', BetSelectionStatus::Pending->value)
            ->where('bet_tickets.status', BetTicketStatus::Pending->value)
            ->where(fn ($q) => $q->where('bet_tickets.type', BetType::Single->value)
                ->orWhereNotExists(fn ($other) => $other->selectRaw('1')->from('bet_selections as others')
                    ->whereColumn('others.bet_ticket_id', 'bet_tickets.id')
                    ->whereColumn('others.id', '!=', 'bet_selections.id')
                    ->whereNotIn('others.status', $settled)))
            ->groupBy('bet_selections.event_id', 'bet_selections.market_id', 'bet_selections.selection_key')
            ->select(['bet_selections.event_id', 'bet_selections.market_id'])
            ->selectRaw('SUM(bet_tickets.potential_payout_minor) AS payout_after')
            ->selectRaw('SUM(CASE WHEN bet_tickets.id = ? THEN 0 ELSE bet_tickets.potential_payout_minor END) AS payout_before', [$newTicketId ?? 0])
            ->selectRaw('SUM(CASE WHEN bet_tickets.type = ? THEN bet_tickets.stake_minor ELSE 0 END) AS stakes_after', [BetType::Single->value])
            ->selectRaw('SUM(CASE WHEN bet_tickets.type = ? AND bet_tickets.id <> ? THEN bet_tickets.stake_minor ELSE 0 END) AS stakes_before', [BetType::Single->value, $newTicketId ?? 0])
            ->get();

        $markets = $rows->groupBy('market_id')->map(fn (Collection $selections) => [
            'event_id' => (int) $selections->first()->event_id,
            'before' => (int) $selections->max('payout_before') - (int) $selections->sum('stakes_before'),
            'after' => (int) $selections->max('payout_after') - (int) $selections->sum('stakes_after'),
        ]);

        $events = $markets->groupBy('event_id')->map(fn (Collection $eventMarkets) => [
            'before' => (int) $eventMarkets->sum('before'),
            'after' => (int) $eventMarkets->sum('after'),
        ]);

        return ['markets' => $markets->all(), 'events' => $events->all()];
    }

    /**
     * @param  array{markets: array<int, array{after: int}>, events: array<int, array{after: int}>}  $exposure
     * @param  list<int>  $marketIds
     */
    private function nearLimit(array $exposure, array $marketIds): bool
    {
        $marketHalf = intdiv((int) config('betting.max_market_liability_minor'), 2);
        $eventHalf = intdiv((int) config('betting.max_event_liability_minor'), 2);

        return collect($marketIds)->contains(fn (int $id) => ($exposure['markets'][$id]['after'] ?? 0) > $marketHalf)
            || collect($exposure['events'])->contains(fn (array $event) => $event['after'] > $eventHalf);
    }

    /** Le joueur ne voit ni le plafond ni l'exposition de la maison : seulement le match concerné. */
    private function refuse(string $scope, int $eventId): never
    {
        throw new ApiException('risk_limit_exceeded', __('Ce pari dépasse le montant que nous acceptons sur ce match : réduisez la mise ou choisissez une autre sélection.'), 422, [
            'scope' => $scope,
            'event_id' => (string) $eventId,
        ]);
    }
}
