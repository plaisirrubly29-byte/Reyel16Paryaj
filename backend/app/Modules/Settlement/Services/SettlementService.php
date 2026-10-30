<?php

namespace App\Modules\Settlement\Services;

use App\Models\User;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\Settlement;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonInterface;
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
 *   correction auditée (P5-06, reevaluate()), qui ajoute une révision et des écritures compensatoires.
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
            // Match joué plus de postponed_void_hours après l'heure du pari : annulé, quel que soit le score (D-05).
            if ($event->starts_at->gt($this->postponedDeadline($selection))) {
                $report->selections += $this->voidPostponed($selection);
                $ticketIds[$selection->bet_ticket_id] = true;

                continue;
            }

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
     * Rattrapage (commande planifiée) : matchs reportés sans résultat (D-05), matchs avec un résultat et des
     * sélections encore en attente, puis tickets dont toutes les sélections sont réglées mais restés "pending"
     * (règlement interrompu).
     */
    public function sweep(): SettlementReport
    {
        $report = new SettlementReport;

        $this->sweepPostponed($report);

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
            // 1 au premier règlement ; davantage si une correction (P5-06) a rouvert le ticket.
            $revision = $this->nextRevision($locked);

            $entry = match ($status) {
                BetTicketStatus::Won => $this->ledger->payout($locked->wallet, $payout, $this->reference($locked, 'payout', $revision), (string) $locked->id),
                BetTicketStatus::Void => $this->ledger->refund($locked->wallet, $payout, $this->reference($locked, 'refund', $revision), (string) $locked->id),
                default => null,
            };

            $settlement = Settlement::create([
                'bet_ticket_id' => $locked->id,
                'revision' => $revision,
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

    /**
     * Matchs reportés sans résultat (D-05) : délai dépassé et match déplacé au-delà (ou marqué reporté).
     * Un match resté à son heure dont le score manque n'est pas annulé : il attend son résultat ou une saisie.
     */
    private function sweepPostponed(SettlementReport $report): void
    {
        $ticketIds = [];

        BetSelection::where('status', BetSelectionStatus::Pending)
            ->where('event_starts_at', '<', now())
            ->whereHas('event', fn ($q) => $q->whereIn('status', [EventStatus::Scheduled, EventStatus::Postponed]))
            ->with(['event', 'ticket'])
            ->orderBy('id')
            ->each(function (BetSelection $selection) use ($report, &$ticketIds) {
                $deadline = $this->postponedDeadline($selection);
                $event = $selection->event;

                if (now()->lte($deadline) || Result::currentFor($event) !== null) {
                    return;
                }

                if ($event->status === EventStatus::Postponed || $event->starts_at->gt($deadline)) {
                    $report->selections += $this->voidPostponed($selection);
                    $ticketIds[$selection->bet_ticket_id] = true;
                }
            });

        foreach (array_keys($ticketIds) as $ticketId) {
            $this->settleTicket(BetTicket::findOrFail($ticketId), $report);
        }
    }

    /** Heure limite de début du match : heure figée sur le ticket + délai de ses règles (ou de la config). */
    private function postponedDeadline(BetSelection $selection): CarbonInterface
    {
        $hours = $selection->ticket->rules_snapshot['postponed_void_hours'] ?? config('betting.postponed_void_hours');

        return $selection->event_starts_at->copy()->addHours((int) $hours);
    }

    /**
     * Annule une sélection de match reporté. Sans result_id : la réévaluation d'une correction de score (P5-06)
     * ne la touche jamais, le report ne dépendant pas du score.
     */
    private function voidPostponed(BetSelection $selection): int
    {
        $updated = BetSelection::whereKey($selection->id)->where('status', BetSelectionStatus::Pending)->update([
            'status' => BetSelectionStatus::Void,
            'result_id' => null,
            'settled_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated > 0) {
            Log::info('settlement.selection_postponed', ['bet_selection_id' => $selection->id, 'event_id' => $selection->event_id]);
        }

        return $updated;
    }

    /** Référence unique de l'écriture du ledger d'un règlement (révision 1 ; P5-06 ajoute les suivantes). */
    private function reference(BetTicket $ticket, string $kind, int $revision): string
    {
        return "settlement:{$ticket->id}:r{$revision}:{$kind}";
    }

    private function nextRevision(BetTicket $ticket): int
    {
        return (int) Settlement::where('bet_ticket_id', $ticket->id)->max('revision') + 1;
    }

    /**
     * Réévaluation des sélections déjà réglées d'un match, avec une nouvelle version de son résultat (P5-06).
     *
     * Pour chaque ticket dont l'issue change : écriture compensatoire du gain ou du remboursement précédent,
     * nouvelle écriture si besoin, nouvelle révision du règlement. Un ticket perdu à cause de ce match redevient
     * « pending » si d'autres matchs du combiné ne sont pas encore réglés. Le solde peut devenir négatif (reprise).
     *
     * @param  bool  $apply  false : aperçu, rien n'est écrit ($result peut alors être une version non enregistrée)
     * @return list<array{ticket_id: string, reference: string, user_id: string, from: array{status: string, payout_minor: int}, to: array{status: string, payout_minor: int}, ledger_delta_minor: int}>
     */
    public function reevaluate(Event $event, Result $result, bool $apply, ?User $actor = null, string $reason = ''): array
    {
        $changed = [];
        // Sans result_id : sélection annulée pour report (D-05), indépendante du score.
        $settled = BetSelection::where('event_id', $event->id)->where('status', '!=', BetSelectionStatus::Pending)->whereNotNull('result_id');
        foreach ($settled->get() as $selection) {
            try {
                $status = $this->outcomes->resolve($selection->market_key, $selection->market_line, $selection->selection_key, $result);
            } catch (InvalidArgumentException $e) {
                Log::error('settlement.selection_unresolvable', ['bet_selection_id' => $selection->id, 'error' => $e->getMessage()]);

                continue; // marché inconnu : la sélection garde son règlement, signalé
            }

            if ($status !== $selection->status) {
                $changed[$selection->id] = $status;
            }
        }

        $ticketIds = BetSelection::whereKey(array_keys($changed))->distinct()->orderBy('bet_ticket_id')->pluck('bet_ticket_id');

        $changes = [];
        foreach ($ticketIds as $ticketId) {
            $change = $apply
                ? DB::transaction(fn () => $this->reevaluateTicket($ticketId, $changed, $result, true, $actor, $reason))
                : $this->reevaluateTicket($ticketId, $changed, $result, false, $actor, $reason);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        if ($apply) {
            $this->resettleCatalogue($event, $result);
        }

        return $changes;
    }

    /**
     * @param  array<int, BetSelectionStatus>  $changed  nouveau statut des sélections dont l'issue change
     * @return array<string, mixed>|null null si l'issue du ticket ne change pas
     */
    private function reevaluateTicket(int $ticketId, array $changed, Result $result, bool $apply, ?User $actor, string $reason): ?array
    {
        /** @var BetTicket $ticket */
        $ticket = $apply ? BetTicket::whereKey($ticketId)->lockForUpdate()->firstOrFail() : BetTicket::findOrFail($ticketId);
        $selections = $ticket->selections()->get();

        foreach ($selections as $selection) {
            if (isset($changed[$selection->id])) {
                $selection->status = $changed[$selection->id];
                $selection->result_id = $result->id;
                $selection->settled_at = now();
            }
        }

        $statuses = $selections->pluck('status');
        [$to, $payout] = match (true) {
            $statuses->contains(BetSelectionStatus::Lost) => [BetTicketStatus::Lost, Money::zero($ticket->currency)],
            $statuses->contains(BetSelectionStatus::Pending) => [BetTicketStatus::Pending, Money::zero($ticket->currency)],
            default => $this->outcome($ticket, $selections->all()),
        };
        $from = $ticket->status;
        $fromPayout = $ticket->status === BetTicketStatus::Pending ? 0 : (int) $ticket->payout_minor;

        if ($apply) {
            $selections->filter(fn (BetSelection $s) => $s->isDirty())->each->save();
        }

        if ($from === $to && $fromPayout === $payout->minor) {
            return null;
        }

        if ($apply) {
            $last = Settlement::where('bet_ticket_id', $ticket->id)->orderByDesc('revision')->first();
            $revision = ($last->revision ?? 0) + 1;

            if ($last?->ledger_entry_id !== null) {
                $this->ledger->reverse(LedgerEntry::findOrFail($last->ledger_entry_id), $this->reference($ticket, 'reversal', $revision), $reason, $actor);
            }

            $entry = match ($to) {
                BetTicketStatus::Won => $this->ledger->payout($ticket->wallet, $payout, $this->reference($ticket, 'payout', $revision), (string) $ticket->id),
                BetTicketStatus::Void => $this->ledger->refund($ticket->wallet, $payout, $this->reference($ticket, 'refund', $revision), (string) $ticket->id),
                default => null,
            };

            // Un ticket rouvert (pending) n'a pas de règlement : il en aura un quand ses matchs seront joués.
            if ($to !== BetTicketStatus::Pending) {
                Settlement::create([
                    'bet_ticket_id' => $ticket->id,
                    'revision' => $revision,
                    'status' => $to,
                    'payout_minor' => $payout->minor,
                    'currency' => $ticket->currency,
                    'ledger_entry_id' => $entry?->id,
                    'results' => $selections->mapWithKeys(fn (BetSelection $s) => [(string) $s->event_id => $s->result_id])->all(),
                ]);
            }

            $ticket->update($to === BetTicketStatus::Pending
                ? ['status' => $to, 'payout_minor' => null, 'settled_at' => null]
                : ['status' => $to, 'payout_minor' => $payout->minor, 'settled_at' => now()]);

            Log::warning('settlement.ticket_reevaluated', ['ticket' => $ticket->reference, 'from' => $from->value, 'to' => $to->value, 'payout_minor' => $payout->minor]);
        }

        return [
            'ticket_id' => (string) $ticket->id,
            'reference' => $ticket->reference,
            'user_id' => (string) $ticket->user_id,
            'from' => ['status' => $from->value, 'payout_minor' => $fromPayout],
            'to' => ['status' => $to->value, 'payout_minor' => $payout->minor],
            'ledger_delta_minor' => $payout->minor - $fromPayout,
        ];
    }

    /** Issue des sélections du catalogue d'un match déjà réglé, recalculée avec la version corrigée. */
    private function resettleCatalogue(Event $event, Result $result): void
    {
        foreach ($event->markets()->with('selections')->get() as $market) {
            try {
                foreach ($market->selections as $selection) {
                    $selection->update(['result' => $this->outcomes->resolve($market->key, $market->line, $selection->key, $result)->value]);
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }
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
