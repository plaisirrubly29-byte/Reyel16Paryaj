<?php

namespace App\Modules\Settlement\Data;

/** Statistiques d'un match terminé (V2-D09) ; null : valeur non fournie. Cartons = jaunes + rouges. */
final readonly class StatisticsData
{
    public function __construct(
        public string $externalId,
        public ?int $firstHalfHome,
        public ?int $firstHalfAway,
        public ?int $cornersHome,
        public ?int $cornersAway,
        public ?int $cardsHome,
        public ?int $cardsAway,
    ) {}
}
