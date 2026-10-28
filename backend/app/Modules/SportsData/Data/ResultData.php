<?php

namespace App\Modules\SportsData\Data;

use Carbon\CarbonImmutable;

final readonly class ResultData
{
    public function __construct(
        public string $externalId,
        public string $sportKey,
        public string $homeTeam,
        public string $awayTeam,
        public CarbonImmutable $startsAt,
        /** true quand le fournisseur considère le match terminé (score final). */
        public bool $completed,
        public ?string $homeScore,
        public ?string $awayScore,
        public ?CarbonImmutable $updatedAt,
    ) {}
}
