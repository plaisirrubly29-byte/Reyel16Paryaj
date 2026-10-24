<?php

namespace App\Modules\SportsData\Data;

use Carbon\CarbonImmutable;

final readonly class QuotaData
{
    public function __construct(
        public int $remaining,
        public int $used,
        /** Coût de la dernière requête. */
        public int $lastCost,
        public CarbonImmutable $observedAt,
    ) {}
}
