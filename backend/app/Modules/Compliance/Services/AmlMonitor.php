<?php

namespace App\Modules\Compliance\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Compliance\Contracts\DepositHistory;
use App\Modules\Compliance\Contracts\StakeHistory;
use App\Modules\Compliance\Enums\AmlAlertStatus;
use App\Modules\Compliance\Enums\AmlMovement;
use App\Modules\Compliance\Enums\AmlRule;
use App\Modules\Compliance\Models\AmlAlert;
use App\Support\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Règles AML (V2-C04) : chaque dépôt ou retrait est contrôlé ; une règle déclenchée crée une alerte pour la
 * Conformité, sans rien bloquer (aucun blocage automatique sans revue). Seuils dans config/compliance.php (aml),
 * provisoires en attendant l'avis juridique (D-01, question J-6).
 */
class AmlMonitor
{
    public function __construct(
        private readonly DepositHistory $deposits,
        private readonly StakeHistory $stakes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Applique les règles au mouvement et crée une alerte par règle déclenchée.
     *
     * @return list<AmlAlert>
     */
    public function check(User $user, int $ledgerEntryId, AmlMovement $movement, int $amountMinor, string $currency, Carbon $occurredAt): array
    {
        $triggered = $movement === AmlMovement::Deposit
            ? $this->depositRules($user, $amountMinor, $occurredAt)
            : $this->withdrawalRules($user, $occurredAt);

        $alerts = [];
        foreach ($triggered as $rule => $details) {
            $alerts[] = $this->raise($user, AmlRule::from($rule), $ledgerEntryId, $amountMinor, $currency, $details);
        }

        return $alerts;
    }

    /**
     * Classe (fausse alerte, activité justifiée) ou signale (soupçon, à déclarer) une alerte ouverte. Audité avec le motif.
     *
     * @throws ApiException self_review_forbidden, alert_not_open
     */
    public function review(User $reviewer, AmlAlert $alert, AmlAlertStatus $decision, string $reason): AmlAlert
    {
        if ($decision === AmlAlertStatus::Open) {
            throw new InvalidArgumentException("Une revue classe ou signale l'alerte.");
        }

        return DB::transaction(function () use ($reviewer, $alert, $decision, $reason) {
            $locked = AmlAlert::whereKey($alert->id)->lockForUpdate()->firstOrFail();

            if ($locked->user_id === $reviewer->id) {
                throw new ApiException('self_review_forbidden', __('Une alerte sur votre propre compte doit être traitée par un autre administrateur.'), 403);
            }

            if ($locked->status !== AmlAlertStatus::Open) {
                throw new ApiException('alert_not_open', __('Cette alerte a déjà été traitée.'), 409, [
                    'status' => $locked->status->value,
                ]);
            }

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'review_reason' => $reason,
                'reviewed_at' => now(),
            ]);

            $this->audit->record(AuditAction::AmlAlertReviewed, $locked,
                before: ['status' => AmlAlertStatus::Open->value],
                after: ['status' => $decision->value],
                reason: $reason,
                actor: $reviewer,
                metadata: ['user_id' => $locked->user_id, 'rule' => $locked->rule->value, 'ledger_entry_id' => $locked->ledger_entry_id],
            );

            return $locked;
        });
    }

    /** @return array<string, array<string, mixed>> règle => détails */
    private function depositRules(User $user, int $amountMinor, Carbon $occurredAt): array
    {
        $triggered = [];
        $config = config('compliance.aml');

        $large = (int) $config['large_deposit_minor'];
        if ($amountMinor >= $large) {
            $triggered[AmlRule::LargeDeposit->value] = ['threshold_minor' => $large];
        }

        // Une alerte quand CE dépôt fait franchir le seuil, pas à chaque dépôt suivant au-dessus du seuil.
        $days = (int) $config['cumulative_deposits']['days'];
        $threshold = (int) $config['cumulative_deposits']['threshold_minor'];
        $total = $this->deposits->depositedSince($user, $occurredAt->copy()->subDays($days));
        if ($total >= $threshold && $total - $amountMinor < $threshold) {
            $triggered[AmlRule::CumulativeDeposits->value] = ['threshold_minor' => $threshold, 'window_days' => $days, 'deposited_minor' => $total];
        }

        return $triggered;
    }

    /** @return array<string, array<string, mixed>> règle => détails */
    private function withdrawalRules(User $user, Carbon $occurredAt): array
    {
        $config = config('compliance.aml.withdrawal_without_play');
        $hours = (int) $config['hours'];
        $percent = (int) $config['min_stake_percent'];
        $since = $occurredAt->copy()->subHours($hours);

        $deposited = $this->deposits->depositedSince($user, $since);
        if ($deposited === 0) {
            return [];
        }

        $staked = $this->stakes->stakedSince($user, $since);
        if ($staked * 100 >= $deposited * $percent) {
            return [];
        }

        return [AmlRule::WithdrawalWithoutPlay->value => [
            'window_hours' => $hours,
            'min_stake_percent' => $percent,
            'deposited_minor' => $deposited,
            'staked_minor' => $staked,
        ]];
    }

    /** @param array<string, mixed> $details */
    private function raise(User $user, AmlRule $rule, int $ledgerEntryId, int $amountMinor, string $currency, array $details): AmlAlert
    {
        // Job rejoué : l'alerte de cette règle pour cette écriture existe déjà.
        $existing = AmlAlert::where('rule', $rule)->where('ledger_entry_id', $ledgerEntryId)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $alert = AmlAlert::create([
                'user_id' => $user->id,
                'rule' => $rule,
                'ledger_entry_id' => $ledgerEntryId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'details' => $details,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Même écriture contrôlée deux fois en parallèle.
            return AmlAlert::where('rule', $rule)->where('ledger_entry_id', $ledgerEntryId)->firstOrFail();
        }

        Log::warning('aml.alert_raised', ['alert_id' => $alert->id, 'user_id' => $user->id, 'rule' => $rule->value, 'ledger_entry_id' => $ledgerEntryId]);

        return $alert;
    }
}
