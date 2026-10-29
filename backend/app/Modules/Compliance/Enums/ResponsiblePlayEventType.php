<?php

namespace App\Modules\Compliance\Enums;

/** Événements du journal de jeu responsable (P7-02). */
enum ResponsiblePlayEventType: string
{
    case LimitLowered = 'limit.lowered';
    case LimitIncreaseScheduled = 'limit.increase_scheduled';
    case LimitChangeApplied = 'limit.change_applied';
    case PauseStarted = 'pause.started';
    case SelfExclusionStarted = 'self_exclusion.started';
    case BetRefusedPause = 'bet.refused_pause';
    case BetRefusedLimit = 'bet.refused_limit';
}
