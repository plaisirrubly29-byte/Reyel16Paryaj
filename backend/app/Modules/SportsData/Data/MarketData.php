<?php

namespace App\Modules\SportsData\Data;

use Carbon\CarbonImmutable;

/** Un marché (ex. "h2h" = vainqueur du match) proposé par un bookmaker. */
final readonly class MarketData
{
    /**
     * @param  list<SelectionData>  $selections
     */
    public function __construct(
        public string $key,
        public string $bookmaker,
        /** Horodatage de la cote chez le fournisseur : sert à la règle de fraîcheur (P3-04). */
        public CarbonImmutable $updatedAt,
        public array $selections,
    ) {}
}
