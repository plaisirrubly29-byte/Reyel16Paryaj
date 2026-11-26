<?php

namespace App\Modules\Settlement\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use App\Modules\Settlement\Models\TicketVoid;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un ticket par l'administration (erreur de cote, fraude), à double validation — même circuit que les
 * corrections de résultat (P5-06, D-07) :
 *
 * 1. Demande (tickets.void) avec motif : rien ne change.
 * 2. Validation par un AUTRE administrateur (tickets.void_approve) : sélections à la cote 1, mise rendue ; un gain ou
 *    un remboursement déjà versé est d'abord compensé. Tout dans une transaction.
 * 3. Refus (tickets.void_approve) ou retrait par le demandeur.
 *
 * Un ticket déjà réglé ne s'annule que dans le délai des corrections (D-05 §5, 72 h après son règlement) ; au-delà,
 * c'est une correction financière.
 */
class TicketVoidService
{
    public function __construct(
        private readonly SettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ApiException ticket_not_voidable | correction_window_closed | void_already_pending
     */
    public function request(User $requester, BetTicket $ticket, string $reason): TicketVoid
    {
        return DB::transaction(function () use ($requester, $ticket, $reason) {
            $locked = BetTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertVoidable($locked);

            if (($pending = TicketVoid::where('bet_ticket_id', $locked->id)->where('status', TicketVoidStatus::Pending)->first()) !== null) {
                throw new ApiException('void_already_pending', __('Une annulation de ce ticket attend déjà sa validation.'), 409, ['ticket_void_id' => (string) $pending->id]);
            }

            $void = TicketVoid::create([
                'bet_ticket_id' => $locked->id,
                'status' => TicketVoidStatus::Pending,
                'ticket_status' => $locked->status,
                'reason' => $reason,
                'requested_by' => $requester->id,
            ]);

            $this->audit->record(AuditAction::TicketVoidRequested, $void,
                before: ['ticket_status' => $locked->status->value, 'payout_minor' => $locked->payout_minor],
                reason: $reason, actor: $requester,
                metadata: ['ticket_id' => (string) $locked->id, 'reference' => $locked->reference]);

            return $void;
        });
    }

    /**
     * Effet de la validation si elle avait lieu maintenant (rien n'est écrit).
     *
     * @return array<string, mixed>
     */
    public function preview(TicketVoid $void): array
    {
        return $this->settlement->voidTicket($void->ticket, apply: false);
    }

    /**
     * @throws ApiException self_approval_forbidden | approver_role_too_recent | void_not_pending | ticket_changed | ticket_not_voidable | correction_window_closed
     */
    public function approve(User $approver, TicketVoid $void, string $reason): TicketVoid
    {
        return DB::transaction(function () use ($approver, $void, $reason) {
            $void = $this->lockPending($void);

            if ($void->requested_by === $approver->id) {
                throw new ApiException('self_approval_forbidden', __('Une annulation de ticket doit être validée par un autre administrateur.'), 403);
            }

            // M3 (D-07) : un compte qui vient de recevoir son rôle ne sert pas de second validateur.
            if ($approver->holdsPermissionOnlyRecently('tickets.void_approve', (int) config('identity.approval_role_min_age_hours'))) {
                throw new ApiException('approver_role_too_recent', __('Votre rôle est trop récent pour valider une demande.'), 403);
            }

            $ticket = BetTicket::whereKey($void->bet_ticket_id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== $void->ticket_status) {
                throw new ApiException('ticket_changed', __('Le ticket a changé depuis la demande : refusez-la et faites une nouvelle demande.'), 409,
                    ['ticket_status' => $ticket->status->value]);
            }
            $this->assertVoidable($ticket);

            $impact = $this->settlement->voidTicket($ticket, apply: true, actor: $approver, reason: $void->reason);

            $void->forceFill([
                'status' => TicketVoidStatus::Approved,
                'decided_by' => $approver->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'impact' => $impact,
            ])->save();

            $this->audit->record(AuditAction::TicketVoidApproved, $void,
                before: $impact['from'], after: $impact['to'], reason: $reason, actor: $approver,
                metadata: ['ticket_id' => (string) $ticket->id, 'reference' => $ticket->reference, 'ledger_delta_minor' => $impact['ledger_delta_minor']]);

            return $void;
        });
    }

    /**
     * Refus par un administrateur tickets.void_approve, ou retrait par le demandeur.
     *
     * @throws AuthorizationException ni demandeur ni validateur
     * @throws ApiException void_not_pending
     */
    public function reject(User $decider, TicketVoid $void, string $reason): TicketVoid
    {
        return DB::transaction(function () use ($decider, $void, $reason) {
            $void = $this->lockPending($void);

            if ($void->requested_by !== $decider->id && ! $decider->hasPermission('tickets.void_approve')) {
                throw new AuthorizationException;
            }

            $void->forceFill([
                'status' => TicketVoidStatus::Rejected,
                'decided_by' => $decider->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::TicketVoidRejected, $void, reason: $reason, actor: $decider,
                metadata: ['ticket_id' => (string) $void->bet_ticket_id, 'withdrawn' => $void->requested_by === $decider->id]);

            return $void;
        });
    }

    /** Ticket en cours, gagné ou perdu ; réglé depuis moins que le délai des corrections (D-05 §5). */
    private function assertVoidable(BetTicket $ticket): void
    {
        if (! in_array($ticket->status, [BetTicketStatus::Pending, BetTicketStatus::Won, BetTicketStatus::Lost], true)) {
            throw new ApiException('ticket_not_voidable', __('Seul un ticket en cours, gagné ou perdu peut être annulé.'), 409, ['status' => $ticket->status->value]);
        }

        if ($ticket->status->isSettled() && $ticket->settled_at !== null) {
            $deadline = CarbonImmutable::parse($ticket->settled_at)->addHours((int) config('betting.result_correction_window_hours'));
            if (now()->gt($deadline)) {
                throw new ApiException('correction_window_closed', __('Le délai d\'annulation de ce ticket réglé est dépassé : passez par une correction financière.'), 409,
                    ['deadline' => $deadline->toIso8601String()]);
            }
        }
    }

    private function lockPending(TicketVoid $void): TicketVoid
    {
        $locked = TicketVoid::whereKey($void->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== TicketVoidStatus::Pending) {
            throw new ApiException('void_not_pending', __('Cette demande a déjà été traitée.'), 409, ['status' => $locked->status->value]);
        }

        return $locked;
    }
}
