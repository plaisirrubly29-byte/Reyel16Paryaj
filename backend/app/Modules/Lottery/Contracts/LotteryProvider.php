<?php

namespace App\Modules\Lottery\Contracts;

use App\Modules\Lottery\Data\DrawData;
use App\Modules\Lottery\Data\DrawResultData;
use App\Modules\Lottery\Exceptions\LotteryException;
use Carbon\CarbonImmutable;

/**
 * Accès au fournisseur de tirages de borlette. Le reste de l'application ne connaît que ce contrat : le vrai
 * fournisseur, le faux fournisseur de développement et de test, ou un autre fournisseur plus tard.
 *
 * L'adaptateur traduit la réponse du fournisseur en trois lots de deux chiffres (et en numéros bruts s'il les
 * donne) : la règle de calcul des lots, si le fournisseur ne la fait pas lui-même, vit dans l'adaptateur.
 *
 * Implémentation choisie par LOTTERY_PROVIDER (config/lottery.php).
 */
interface LotteryProvider
{
    /** Identifiant du fournisseur, enregistré comme source des tirages et des résultats. */
    public function name(): string;

    /**
     * Tirages proposés (nom, heure, fuseau, jours).
     *
     * @return list<DrawData>
     *
     * @throws LotteryException
     */
    public function draws(): array;

    /**
     * Résultats publiés pour les tirages des dates $from à $to incluses (dates locales des tirages).
     *
     * @return list<DrawResultData>
     *
     * @throws LotteryException
     */
    public function results(CarbonImmutable $from, CarbonImmutable $to): array;
}
