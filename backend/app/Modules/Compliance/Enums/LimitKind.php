<?php

namespace App\Modules\Compliance\Enums;

use App\Modules\Compliance\Models\BettingLimit;
use App\Modules\Compliance\Models\DepositLimit;

/** Ce que borne une limite de jeu responsable : les mises (P7-02) ou les dépôts (V2-B06). */
enum LimitKind: string
{
    case Stake = 'stake';
    case Deposit = 'deposit';

    /** @return class-string<BettingLimit> */
    public function model(): string
    {
        return match ($this) {
            self::Stake => BettingLimit::class,
            self::Deposit => DepositLimit::class,
        };
    }

    public function loweredEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitLowered,
            self::Deposit => ResponsiblePlayEventType::DepositLimitLowered,
        };
    }

    public function increaseScheduledEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitIncreaseScheduled,
            self::Deposit => ResponsiblePlayEventType::DepositLimitIncreaseScheduled,
        };
    }

    public function changeAppliedEvent(): ResponsiblePlayEventType
    {
        return match ($this) {
            self::Stake => ResponsiblePlayEventType::LimitChangeApplied,
            self::Deposit => ResponsiblePlayEventType::DepositLimitChangeApplied,
        };
    }
}
