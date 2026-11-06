<?php

namespace App\Modules\Compliance\Enums;

/** Événements du journal de jeu responsable (P7-02 ; limites de dépôt : V2-B06 ; pertes et rappel de session : V2-E06). */
enum ResponsiblePlayEventType: string
{
    case LimitLowered = 'limit.lowered';
    case LimitIncreaseScheduled = 'limit.increase_scheduled';
    case LimitChangeApplied = 'limit.change_applied';
    case PauseStarted = 'pause.started';
    case SelfExclusionStarted = 'self_exclusion.started';
    case SelfExclusionLifted = 'self_exclusion.lifted';
    case BetRefusedPause = 'bet.refused_pause';
    case BetRefusedLimit = 'bet.refused_limit';
    case DepositLimitLowered = 'deposit_limit.lowered';
    case DepositLimitIncreaseScheduled = 'deposit_limit.increase_scheduled';
    case DepositLimitChangeApplied = 'deposit_limit.change_applied';
    case DepositRefusedLimit = 'deposit.refused_limit';
    case LossLimitLowered = 'loss_limit.lowered';
    case LossLimitIncreaseScheduled = 'loss_limit.increase_scheduled';
    case LossLimitChangeApplied = 'loss_limit.change_applied';
    case BetRefusedLossLimit = 'bet.refused_loss_limit';
    case SessionReminderChanged = 'session_reminder.changed';
}
