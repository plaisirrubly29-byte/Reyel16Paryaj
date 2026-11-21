<?php

namespace App\Modules\Settlement\Data;

use Carbon\CarbonImmutable;

/** Match chez le fournisseur de statistiques (V2-D09). */
final readonly class FixtureData
{
    public function __construct(
        public string $externalId,
        public string $homeTeam,
        public string $awayTeam,
        public CarbonImmutable $startsAt,
        /** true : terminé à la fin du temps réglementaire (ni prolongation ni tirs au but). */
        public bool $finished,
        public ?int $homeGoals,
        public ?int $awayGoals,
        public ?int $firstHalfHome,
        public ?int $firstHalfAway,
        /** Identifiants des équipes chez le fournisseur (pour lire leurs statistiques). */
        public ?string $homeTeamId = null,
        public ?string $awayTeamId = null,
    ) {}
}
