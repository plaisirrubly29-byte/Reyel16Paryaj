<?php

namespace App\Modules\SportsData\Data;

use Carbon\CarbonImmutable;

final readonly class EventData
{
    /**
     * @param  list<MarketData>  $markets
     */
    public function __construct(
        /** Identifiant chez le fournisseur (unique par fournisseur). */
        public string $externalId,
        public string $sportKey,
        public string $competition,
        public string $homeTeam,
        public string $awayTeam,
        public CarbonImmutable $startsAt,
        public array $markets,
    ) {}
}
