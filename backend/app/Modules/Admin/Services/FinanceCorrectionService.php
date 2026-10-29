<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Correction financière à double validation (P6-04) : un administrateur demande la compensation d'une écriture
 * (finance.correct), un AUTRE la valide ou la refuse (finance.approve). Le demandeur peut retirer sa demande
 * (refus), jamais la valider. Chaque étape est auditée avec son motif.
 *
 * V1 : seules les écritures sans ticket (crédits simulés) sont corrigeables. Corriger la mise ou le gain d'un
 * ticket le rendrait incohérent avec son statut : cela passe par la correction de résultat (P5-06).
 */
class FinanceCorrectionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** Raison pour laquelle l'écriture ne peut pas être corrigée, ou null si elle le peut. */
    public static function blocker(LedgerEntry $entry): ?string
    {
        return match (true) {
            $entry->type === LedgerEntryType::Reversal => 'reversal_entry',
            $entry->related_type !== null => 'linked_to_ticket',
            // Relation préchargée par les listes (une requête par écriture sinon).
            $entry->relationLoaded('reversedBy') ? $entry->reversedBy !== null : $entry->reversedBy()->exists() => 'already_reversed',
            default => null,
        };
    }

    public function request(User $requester, LedgerEntry $entry, string $reason): FinanceCorrection
    {
        return DB::transaction(function () use ($requester, $entry, $reason) {
            // Verrou sur l'écriture : deux demandes simultanées ne passent pas toutes les deux.
            $entry = LedgerEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->assertCorrectable($entry);

            $pending = FinanceCorrection::where('ledger_entry_id', $entry->id)->where('status', FinanceCorrectionStatus::Pending)->first();
            if ($pending !== null) {
                throw new ApiException('correction_already_pending', __('Une correction est déjà en attente pour cette écriture.'), 409, [
                    'correction_id' => (string) $pending->id,
                ]);
            }

            $correction = FinanceCorrection::create([
                'ledger_entry_id' => $entry->id,
                'reason' => $reason,
                'requested_by' => $requester->id,
            ]);

            $this->audit->record(AuditAction::FinanceCorrectionRequested, $correction,
                after: ['status' => FinanceCorrectionStatus::Pending->value, 'ledger_entry_id' => $entry->id, 'amount_minor' => $entry->amount_minor, 'currency' => $entry->currency],
                reason: $reason,
                actor: $requester,
            );

            return $correction;
        });
    }

    /**
     * @throws ApiException self_approval_forbidden, correction_not_pending, entry_not_correctable, wallet_frozen
     */
    public function approve(User $approver, FinanceCorrection $correction, string $reason): FinanceCorrection
    {
        return DB::transaction(function () use ($approver, $correction, $reason) {
            $correction = $this->lockPending($correction);

            if ($correction->requested_by === $approver->id) {
                throw new ApiException('self_approval_forbidden', __('Une correction doit être validée par un autre administrateur.'), 403);
            }

            $entry = LedgerEntry::whereKey($correction->ledger_entry_id)->lockForUpdate()->firstOrFail();
            $this->assertCorrectable($entry);

            $reversal = $this->ledger->reverse($entry, "correction:{$correction->id}:reversal", $correction->reason, $approver);

            $correction->update([
                'status' => FinanceCorrectionStatus::Approved,
                'decided_by' => $approver->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'reversal_entry_id' => $reversal->id,
            ]);

            $this->audit->record(AuditAction::FinanceCorrectionApproved, $correction,
                before: ['status' => FinanceCorrectionStatus::Pending->value],
                after: ['status' => FinanceCorrectionStatus::Approved->value, 'reversal_entry_id' => $reversal->id, 'amount_minor' => $reversal->amount_minor],
                reason: $reason,
                actor: $approver,
                metadata: ['requested_by' => $correction->requested_by, 'ledger_entry_id' => $entry->id],
            );

            return $correction;
        });
    }

    /**
     * @throws ApiException correction_not_pending
     */
    public function reject(User $decider, FinanceCorrection $correction, string $reason): FinanceCorrection
    {
        return DB::transaction(function () use ($decider, $correction, $reason) {
            $correction = $this->lockPending($correction);

            $correction->update([
                'status' => FinanceCorrectionStatus::Rejected,
                'decided_by' => $decider->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ]);

            $this->audit->record(AuditAction::FinanceCorrectionRejected, $correction,
                before: ['status' => FinanceCorrectionStatus::Pending->value],
                after: ['status' => FinanceCorrectionStatus::Rejected->value],
                reason: $reason,
                actor: $decider,
                metadata: ['requested_by' => $correction->requested_by, 'ledger_entry_id' => $correction->ledger_entry_id],
            );

            return $correction;
        });
    }

    private function lockPending(FinanceCorrection $correction): FinanceCorrection
    {
        $locked = FinanceCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== FinanceCorrectionStatus::Pending) {
            throw new ApiException('correction_not_pending', __('Cette correction a déjà été traitée.'), 409, [
                'status' => $locked->status->value,
            ]);
        }

        return $locked;
    }

    private function assertCorrectable(LedgerEntry $entry): void
    {
        $blocker = self::blocker($entry);

        if ($blocker !== null) {
            throw new ApiException('entry_not_correctable', __('Cette écriture ne peut pas être corrigée.'), 422, [
                'reason' => $blocker,
            ]);
        }
    }
}
