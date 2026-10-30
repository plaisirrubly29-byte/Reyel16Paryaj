<?php

namespace App\Modules\Settlement\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Settlement\Enums\ResultCorrectionStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\SportsData\Models\Event;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Correction d'un résultat déjà réglé, à double contrôle (P5-06).
 *
 * 1. Demande (administrateur results.enter, ou fournisseur qui corrige un score après règlement) : rien ne change.
 * 2. Validation par un AUTRE administrateur (results.approve) : nouvelle version du résultat, réévaluation des
 *    tickets (écritures compensatoires, révision du règlement) — tout dans une transaction.
 * 3. Refus par un administrateur results.approve, ou retrait par le demandeur.
 * Chaque étape est auditée avec son motif ; l'aperçu de l'impact ne modifie rien.
 */
class ResultCorrectionService
{
    public const PROVIDER_REASON = 'Correction envoyée par le fournisseur après règlement';

    public function __construct(
        private readonly ResultRecorder $recorder,
        private readonly SettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Demande de correction d'un administrateur.
     *
     * @throws ApiException no_result_to_correct | result_unchanged | correction_window_closed | correction_already_pending | invalid_result
     */
    public function request(User $requester, Event $event, ResultStatus $status, ?string $homeScore, ?string $awayScore, string $reason): ResultCorrection
    {
        return DB::transaction(function () use ($requester, $event, $status, $homeScore, $awayScore, $reason) {
            [$current, $home, $away] = $this->proposal($event, $status, $homeScore, $awayScore);

            if (($deadline = $this->correctionDeadline($event)) !== null && now()->gt($deadline)) {
                throw new ApiException('correction_window_closed', __('Le délai de correction de ce résultat est dépassé.'), 409, ['deadline' => $deadline->toIso8601String()]);
            }

            if (($pending = $this->pendingFor($event)) !== null) {
                throw new ApiException('correction_already_pending', __('Une correction est déjà en attente pour ce match.'), 409, ['correction_id' => (string) $pending->id]);
            }

            return $this->create($event, $current, $status, $home, $away, $reason, 'manual', $requester);
        });
    }

    /**
     * Le fournisseur corrige un score alors que des tickets sont déjà réglés : demande « système », à valider.
     *
     * @return ResultCorrection|null null si la correction ne change rien à la version en vigueur
     *
     * @throws InvalidArgumentException résultat invalide
     */
    public function requestFromProvider(Event $event, ResultStatus $status, ?string $homeScore, ?string $awayScore, string $source, ?string $externalId, ?CarbonImmutable $providerUpdatedAt): ?ResultCorrection
    {
        return DB::transaction(function () use ($event, $status, $homeScore, $awayScore, $source, $externalId, $providerUpdatedAt) {
            Event::whereKey($event->id)->lockForUpdate()->first();
            $current = Result::currentFor($event);
            [$home, $away] = $this->recorder->normalized($status, $homeScore, $awayScore);

            if ($current === null || $current->sameOutcomeAs($status, $home, $away)) {
                return null;
            }

            if (($deadline = $this->correctionDeadline($event)) !== null && now()->gt($deadline)) {
                Log::warning('settlement.correction_window_closed', ['event_id' => $event->id, 'source' => $source, 'id' => $externalId]);

                return null;
            }

            // Déjà une demande en attente (identique, ou d'un administrateur) : on ne la remplace pas.
            if (($pending = $this->pendingFor($event)) !== null) {
                return $pending;
            }

            return $this->create($event, $current, $status, $home, $away, self::PROVIDER_REASON, $source, null, $externalId, $providerUpdatedAt);
        });
    }

    /** Des tickets ont déjà été réglés avec le résultat de ce match : une correction doit être validée. */
    public function requiresApproval(Event $event): bool
    {
        return BetSelection::where('event_id', $event->id)->where('status', '!=', BetSelectionStatus::Pending)->exists();
    }

    /**
     * Impact de la correction si elle était validée maintenant : tickets dont l'issue change, montants.
     *
     * @return array{tickets: list<array<string, mixed>>, tickets_count: int, ledger_delta_minor: int}
     */
    public function preview(ResultCorrection $correction): array
    {
        $proposed = new Result([
            'event_id' => $correction->event_id,
            'status' => $correction->result_status,
            'home_score' => $correction->home_score,
            'away_score' => $correction->away_score,
        ]);

        return $this->summary($this->settlement->reevaluate($correction->event, $proposed, apply: false));
    }

    /**
     * @throws ApiException self_approval_forbidden | correction_not_pending | result_changed
     */
    public function approve(User $approver, ResultCorrection $correction, string $reason): ResultCorrection
    {
        return DB::transaction(function () use ($approver, $correction, $reason) {
            $correction = $this->lockPending($correction);

            if ($correction->requested_by === $approver->id) {
                throw new ApiException('self_approval_forbidden', __('Une correction doit être validée par un autre administrateur.'), 403);
            }

            $event = Event::whereKey($correction->event_id)->lockForUpdate()->firstOrFail();
            $current = Result::currentFor($event);
            if ($current?->id !== $correction->based_on_result_id) {
                throw new ApiException('result_changed', __('Le résultat a changé depuis la demande : refusez-la et faites une nouvelle demande.'), 409);
            }

            $applied = $this->recorder->record(
                $event,
                $correction->result_status,
                $correction->home_score,
                $correction->away_score,
                source: $correction->source,
                externalId: $correction->external_id,
                providerUpdatedAt: $correction->provider_updated_at?->toImmutable(),
                reason: $correction->reason,
                enteredBy: $approver,
            ) ?? throw new ApiException('result_changed', __('Cette correction ne change plus le résultat.'), 409);

            $impact = $this->summary($this->settlement->reevaluate($event, $applied, apply: true, actor: $approver, reason: $correction->reason));

            $correction->forceFill([
                'status' => ResultCorrectionStatus::Approved,
                'decided_by' => $approver->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'applied_result_id' => $applied->id,
                'impact' => $impact,
            ])->save();

            $this->audit->record(AuditAction::ResultCorrectionApproved, $correction,
                before: ['result' => $this->outcome($current)],
                after: ['result' => $this->outcome($applied)],
                reason: $reason,
                actor: $approver,
                metadata: ['event_id' => (string) $event->id, 'tickets_count' => $impact['tickets_count'], 'ledger_delta_minor' => $impact['ledger_delta_minor']]);

            return $correction;
        });
    }

    /**
     * Refus par un administrateur results.approve, ou retrait par le demandeur.
     *
     * @throws AuthorizationException ni demandeur ni validateur
     * @throws ApiException correction_not_pending
     */
    public function reject(User $decider, ResultCorrection $correction, string $reason): ResultCorrection
    {
        return DB::transaction(function () use ($decider, $correction, $reason) {
            $correction = $this->lockPending($correction);

            if ($correction->requested_by !== $decider->id && ! $decider->hasPermission('results.approve')) {
                throw new AuthorizationException;
            }

            $correction->forceFill([
                'status' => ResultCorrectionStatus::Rejected,
                'decided_by' => $decider->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::ResultCorrectionRejected, $correction, reason: $reason, actor: $decider,
                metadata: ['event_id' => (string) $correction->event_id, 'withdrawn' => $correction->requested_by === $decider->id]);

            return $correction;
        });
    }

    /** @return array{0: Result, 1: ?string, 2: ?string} */
    private function proposal(Event $event, ResultStatus $status, ?string $homeScore, ?string $awayScore): array
    {
        Event::whereKey($event->id)->lockForUpdate()->first();

        $current = Result::currentFor($event)
            ?? throw new ApiException('no_result_to_correct', __('Ce match n\'a pas encore de résultat à corriger.'), 409);

        try {
            [$home, $away] = $this->recorder->normalized($status, $homeScore, $awayScore);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('invalid_result', $e->getMessage(), 422);
        }

        if ($current->sameOutcomeAs($status, $home, $away)) {
            throw new ApiException('result_unchanged', __('Le résultat proposé est déjà celui en vigueur.'), 409);
        }

        return [$current, $home, $away];
    }

    private function create(Event $event, Result $current, ResultStatus $status, ?string $home, ?string $away, string $reason, string $source, ?User $requester, ?string $externalId = null, ?CarbonImmutable $providerUpdatedAt = null): ResultCorrection
    {
        $correction = ResultCorrection::create([
            'event_id' => $event->id,
            'based_on_result_id' => $current->id,
            'status' => ResultCorrectionStatus::Pending,
            'result_status' => $status,
            'home_score' => $home,
            'away_score' => $away,
            'source' => $source,
            'external_id' => $externalId,
            'provider_updated_at' => $providerUpdatedAt,
            'reason' => $reason,
            'requested_by' => $requester?->id,
        ]);

        $this->audit->record(AuditAction::ResultCorrectionRequested, $correction,
            before: ['result' => $this->outcome($current)],
            after: ['result' => ['status' => $status->value, 'score' => $home === null ? null : "{$home}-{$away}"]],
            reason: $reason,
            actor: $requester,
            metadata: ['event_id' => (string) $event->id, 'source' => $source]);

        return $correction;
    }

    /** Fin du délai de correction (D-05) : premier règlement du match + délai ; null si rien n'est réglé. */
    private function correctionDeadline(Event $event): ?CarbonImmutable
    {
        $first = BetSelection::where('event_id', $event->id)->whereNotNull('result_id')->min('settled_at');

        return $first === null ? null : CarbonImmutable::parse($first)->addHours((int) config('betting.result_correction_window_hours'));
    }

    private function pendingFor(Event $event): ?ResultCorrection
    {
        return ResultCorrection::where('event_id', $event->id)->where('status', ResultCorrectionStatus::Pending)->first();
    }

    private function lockPending(ResultCorrection $correction): ResultCorrection
    {
        $locked = ResultCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== ResultCorrectionStatus::Pending) {
            throw new ApiException('correction_not_pending', __('Cette correction a déjà été traitée.'), 409, ['status' => $locked->status->value]);
        }

        return $locked;
    }

    /** @return array{status: string, score: ?string, version: int} */
    private function outcome(Result $result): array
    {
        return [
            'status' => $result->status->value,
            'score' => $result->home_score === null ? null : "{$result->home_score}-{$result->away_score}",
            'version' => $result->version,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array{tickets: list<array<string, mixed>>, tickets_count: int, ledger_delta_minor: int}
     */
    private function summary(array $changes): array
    {
        return [
            'tickets' => $changes,
            'tickets_count' => count($changes),
            'ledger_delta_minor' => array_sum(array_column($changes, 'ledger_delta_minor')),
        ];
    }
}
