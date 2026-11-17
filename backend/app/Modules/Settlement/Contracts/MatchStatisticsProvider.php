<?php

namespace App\Modules\Settlement\Contracts;

use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Data\StatisticsData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Carbon\CarbonImmutable;

/**
 * Fournisseur des statistiques des matchs terminés (V2-D09) : score à la mi-temps, corners, cartons.
 * Ses matchs sont rapprochés de ceux du catalogue (The Odds API) par l'heure, les équipes et le score final.
 */
interface MatchStatisticsProvider
{
    public function name(): string;

    /**
     * Matchs de football d'une journée (UTC), avec leur score.
     *
     * @return list<FixtureData>
     *
     * @throws SportsDataException
     */
    public function fixtures(CarbonImmutable $date): array;

    /**
     * Corners et cartons de chaque équipe d'un match terminé (score à la mi-temps repris du match).
     *
     * @throws SportsDataException
     */
    public function statistics(FixtureData $fixture): StatisticsData;
}
