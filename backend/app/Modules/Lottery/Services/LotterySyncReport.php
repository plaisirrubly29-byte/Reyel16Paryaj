<?php

namespace App\Modules\Lottery\Services;

/** Bilan d'une synchronisation des tirages (lottery:sync). */
final readonly class LotterySyncReport
{
    public function __construct(
        public int $draws,
        public int $created,
        public int $unchanged,
        public int $rejected,
        public int $conflicts,
    ) {}
}
