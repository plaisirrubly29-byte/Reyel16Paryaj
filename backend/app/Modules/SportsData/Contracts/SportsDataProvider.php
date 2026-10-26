<?php

namespace App\Modules\SportsData\Contracts;

use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Data\ResultData;
use App\Modules\SportsData\Data\SportData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Carbon\CarbonImmutable;

/**
 * Accès au fournisseur de données sportives. Le reste de l'application ne connaît que ce contrat :
 * on peut changer de fournisseur, ou utiliser le faux fournisseur en développement et en test,
 * sans toucher aux jobs de synchronisation.
 *
 * Implémentation choisie par SPORTS_DATA_PROVIDER (config/sports_data.php).
 */
interface SportsDataProvider
{
    /** Identifiant du fournisseur, enregistré comme source des données synchronisées. */
    public function name(): string;

    /**
     * @return list<SportData>
     *
     * @throws SportsDataException
     */
    public function sports(): array;

    /**
     * Événements à venir d'un sport, avec leurs marchés et cotes.
     *
     * @return list<EventData>
     *
     * @throws SportsDataException
     */
    public function events(string $sportKey): array;

    /**
     * Heures de début des événements à venir d'un sport, sans cotes (gratuit chez The Odds API) :
     * permet de ne pas payer un appel de cotes pour une compétition sans match.
     *
     * @return list<CarbonImmutable>
     *
     * @throws SportsDataException
     */
    public function upcomingStarts(string $sportKey): array;

    /**
     * Résultats (terminés ou en cours) des derniers jours ; $daysFrom = 0 : seulement les matchs en cours
     * (et à venir), moins cher chez The Odds API.
     *
     * @return list<ResultData>
     *
     * @throws SportsDataException
     */
    public function results(string $sportKey, int $daysFrom = 1): array;

    /** Dernier état connu du quota du fournisseur (null s'il n'est pas limité ou pas encore connu). */
    public function quota(): ?QuotaData;
}
