<?php

namespace App\Modules\Settlement\Services;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\Settlement;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Règlement des tickets d'après le résultat en vigueur des matchs (P5-05, architecture §4.3).
 *
 * - Chaque sélection est réglée avec ce que le ticket a figé (marché, ligne, sélection), jamais avec le
 *   catalogue du jour ; un ticket est réglé quand toutes ses sélections le sont (ou dès qu'une est perdue).
 * - Idempotent : le ticket est verrouillé et doit être "pending", un seul règlement par révision
 *   (contrainte unique) et l'écriture du ledger a une référence unique — une relance ne paie jamais deux fois.
 * - Gain : celui fixé à l'acceptation (potential_payout_minor) ; avec des sélections annulées (cote 1),
 *   mise × cotes gagnantes, arrondi à l'unité inférieure et jamais au-delà du gain prévu (politique D-05/D-06).
 * - Seules les sélections encore "pending" sont réglées : un résultat corrigé après règlement relève de la
 *   correction auditée (P5-06).
 */
class SettlementService
{
    public function __construct(
        private readonly OutcomeResolver $outcomes,
        private readonly LedgerService $ledger,
    ) {}

    /** Règle les sélections en attente d'un match qui a un résultat, puis les tickets devenus complets. */
    public function settleEvent(Event $event): SettlementReport
    {
        $report = new SettlementReport;

        $result = Result::currentFor($event);
        if ($result === null) {
            return $report;
        }

        $this->settleCatalogue($event, $result);

        $ticketIds = [];
        $pending = BetSelection::where('event_id', $event->id)->where('status', BetSelectionStatus::Pending)->get();

        foreach ($pending as $selection) {
            try {
                $status = $this->outcomes->resolve($selection->market_key, $selection->market_line, $selection->selection_key, $result);
            } catch (InvalidArgumentException $e) {
                $report->failed++;
                Log::error('settlement.selection_unresolvable', ['bet_selection_id' => $selection->id, 'error' => $e->getMessage()]);

                continue;
            }

            // Mise à jour conditionnelle : un règlement concurrent de la même sélection n'écrit rien.
            $updated = BetSelection::whereKey($selection->id)->where('status', BetSelectionStatus::Pending)->update([
                'status' => $status,
                'result_id' => $result->id,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);

            $report->selections += $updated;
            $ticketIds[$selection->bet_ticket_id] = true;
        }

        foreach (array_keys($ticketIds) as $ticketId) {
            $this->settleTicket(BetTicket::findOrFail($ticketId), $report);
        }

        return $report;
    }

    /**
     * Rattrapage (commande planifiée) : matchs avec un résultat et des sélections encore en attente,
     * puis tickets dont toutes les sélections sont réglées mais restés "pending" (règlement interrompu).
     */
    public function sweep(): SettlementReport
    {
        $report = new SettlementReport;

        Event::whereIn('status', [EventStatus::Finished, EventStatus::Cancelled])
            ->whereIn('id', BetSelection::where('status', BetSelectionStatus::Pending)->select('event_id'))
            ->orderBy('id')
            ->each(fn (Event $event) => $report->merge($this->settleEvent($event)));

        BetTicket::where('status', BetTicketStatus::Pending)
            ->where(fn ($q) => $q
                ->whereDoesntHave('selections', fn ($s) => $s->where('status', BetSelectionStatus::Pending))
                ->orWhereHas('selections', fn ($s) => $s->where('status', BetSelectionStatus::Lost)))
            ->orderBy('id')
            ->each(fn (BetTicket $ticket) => $this->settleTicket($ticket, $report));

        return $report;
    }

    /**
     * Règle un ticket dont toutes les sélections sont réglées (sinon ne fait rien).
     *
     * @return Settlement|null le règlement (existant si le ticket était déjà réglé), null si une sélection attend
     */
    public function settleTicket(BetTicket $ticket, ?SettlementReport $report = null): ?Settlement
    {
        return DB::transaction(function () use ($ticket, $report) {
            /** @var BetTicket $locked */
            $locked = BetTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BetTicketStatus::Pending) {
                return Settlement::where('bet_ticket_id', $locked->id)->orderByDesc('revision')->first();
            }

            // Une sélection perdue suffit à régler un combiné perdu ; sinon toutes doivent être réglées.
            $selections = $locked->selections()->get();
            $statuses = $selections->pluck('status');
            if (! $statuses->contains(BetSelectionStatus::Lost) && $statuses->contains(BetSelectionStatus::Pending)) {
                return null;
            }

            [$status, $payout] = $this->outcome($locked, $selections->all());

            $entry = match ($status) {
                BetTicketStatus::Won => $this->ledger->payout($locked->wallet, $payout, $this->reference($locked, 'payout'), (string) $locked->id),
                BetTicketStatus::Void => $this->ledger->refund($locked->wallet, $payout, $this->reference($locked, 'refund'), (string) $locked->id),
                default => null,
            };

            $settlement = Settlement::create([
                'bet_ticket_id' => $locked->id,
                'revision' => 1,
                'status' => $status,
                'payout_minor' => $payout->minor,
                'currency' => $locked->currency,
                'ledger_entry_id' => $entry?->id,
                'results' => $selections->mapWithKeys(fn (BetSelection $s) => [(string) $s->event_id => $s->result_id])->all(),
            ]);

            $locked->update(['status' => $status, 'payout_minor' => $payout->minor, 'settled_at' => now()]);

            if ($report !== null) {
                $report->tickets[$status->value]++;
                $report->paidMinor += $payout->minor;
            }

            Log::info('settlement.ticket_settled', ['ticket' => $locked->reference, 'status' => $status->value, 'payout_minor' => $payout->minor]);

            return $settlement;
        });
    }

    /**
     * @param  list<BetSelection>  $selections
     * @return array{0: BetTicketStatus, 1: Money}
     */
    private function outcome(BetTicket $ticket, array $selections): array
    {
        $statuses = array_map(fn (BetSelection $s) => $s->status, $selections);

        if (in_array(BetSelectionStatus::Lost, $statuses, true)) {
            return [BetTicketStatus::Lost, Money::zero($ticket->currency)];
        }

        $won = array_filter($selections, fn (BetSelection $s) => $s->status === BetSelectionStatus::Won);

        if ($won === []) {
            return [BetTicketStatus::Void, $ticket->stake()];
        }

        if (count($won) === count($selections)) {
            return [BetTicketStatus::Won, $ticket->potentialPayout()];
        }

        // Sélections annulées : leur cote compte pour 1.
        $odds = array_reduce($won, fn (string $carry, BetSelection $s) => bcmul($carry, $s->odds, 6), '1');
        $payout = (int) bcmul((string) $ticket->stake_minor, $odds, 0);

        return [BetTicketStatus::Won, Money::ofMinor(min($payout, $ticket->potential_payout_minor), $ticket->currency)];
    }

    /** Référence unique de l'écriture du ledger d'un règlement (révision 1 ; P5-06 ajoute les suivantes). */
    private function reference(BetTicket $ticket, string $kind): string
    {
        return "settlement:{$ticket->id}:r1:{$kind}";
    }

    /** Issue des sélections du catalogue (affichage) et marchés réglés. */
    private function settleCatalogue(Event $event, Result $result): void
    {
        foreach ($event->markets()->with('selections')->get() as $market) {
            if ($market->status === MarketStatus::Settled) {
                continue;
            }

            try {
                foreach ($market->selections as $selection) {
                    $selection->update(['result' => $this->outcomes->resolve($market->key, $market->line, $selection->key, $result)->value]);
                }
            } catch (InvalidArgumentException) {
                continue;
            }

            $market->update(['status' => MarketStatus::Settled]);
        }
    }
}
