<?php

namespace App\Modules\Compliance\Enums;

/** Période glissante d'une limite de mise (ex. « jour » = 24 dernières heures). */
enum LimitPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function minutes(): int
    {
        return (int) config("compliance.limits.periods.{$this->value}");
    }
}
