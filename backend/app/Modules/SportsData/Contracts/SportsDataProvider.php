<?php

namespace App\Modules\SportsData\Contracts;

use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Data\ResultData;
use App\Modules\SportsData\Data\SportData;
use App\Modules\SportsData\Exceptions\SportsDataException;

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
     * Résultats (terminés ou en cours) des derniers jours.
     *
     * @return list<ResultData>
     *
     * @throws SportsDataException
     */
    public function results(string $sportKey, int $daysFrom = 1): array;

    /** Dernier état connu du quota du fournisseur (null s'il n'est pas limité ou pas encore connu). */
    public function quota(): ?QuotaData;
}
