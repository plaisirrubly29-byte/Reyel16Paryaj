<?php

namespace App\Modules\SportsData\Data;

use InvalidArgumentException;

final readonly class SelectionData
{
    /**
     * @param  string  $odds  cote décimale en chaîne (ex. "2.15") — jamais de float stocké
     */
    public function __construct(
        public string $name,
        public string $odds,
        /** Ligne du marché (handicap, total) ; null pour un marché vainqueur. */
        public ?string $point = null,
    ) {
        if (! preg_match('/^\d+(\.\d{1,3})?$/', $odds) || bccomp($odds, '1', 3) <= 0) {
            throw new InvalidArgumentException("Cote invalide : {$odds}");
        }
    }

    /**
     * Convertit un nombre JSON du fournisseur en cote décimale exacte (3 décimales max).
     * Passer par une chaîne évite les erreurs d'arrondi des float (ex. 2.1499999).
     */
    public static function oddsFromProvider(int|float|string $value): string
    {
        $fixed = number_format((float) $value, 3, '.', '');

        return rtrim(rtrim($fixed, '0'), '.');
    }
}
