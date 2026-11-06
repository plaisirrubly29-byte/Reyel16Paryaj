<?php

namespace App\Modules\Compliance\Enums;

use App\Modules\Compliance\Models\BettingLimit;
use App\Modules\Compliance\Models\DepositLimit;
use App\Modules\Compliance\Models\LossLimit;

/** Ce que borne une limite de jeu responsable : les mises (P7-02), les dépôts (V2-B06) ou les pertes nettes (V2-E06). */
enum LimitKind: string
{
    case Stake = 'stake';
    case Deposit = 'deposit';
    case Loss = 'loss';

    /** @return class-string<BettingLimit> */
    public function model(): string
    {
        return match ($this) {
            self::Stake => BettingLimit::class,
            self::Deposit => DepositLimit::class,
            self::Loss => LossLimit::class,
        };
    }

    public function loweredEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitLowered,
            self::Deposit => ResponsiblePlayEventType::DepositLimitLowered,
            self::Loss => ResponsiblePlayEventType::LossLimitLowered,
        };
    }

    public function increaseScheduledEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitIncreaseScheduled,
            self::Deposit => ResponsiblePlayEventType::DepositLimitIncreaseScheduled,
            self::Loss => ResponsiblePlayEventType::LossLimitIncreaseScheduled,
        };
    }

    public function changeAppliedEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitChangeApplied,
            self::Deposit => ResponsiblePlayEventType::DepositLimitChangeApplied,
            self::Loss => ResponsiblePlayEventType::LossLimitChangeApplied,
        };
    }
}
