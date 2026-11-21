<?php

namespace App\Modules\SportsData\Enums;

/** Cote fixée à la main (V2-D03, D-18). */
enum OddsOverrideStatus: string
{
    /** Écart de plus de 20 % avec la cote calculée : attend un second administrateur. */
    case Pending = 'pending';

    /** Cote proposée aux joueurs ; la synchronisation ne l'écrase pas. */
    case Active = 'active';

    /** Refusée par le second administrateur (ou retirée par le demandeur). */
    case Rejected = 'rejected';

    /** Fin demandée par un administrateur : retour à la cote calculée. */
    case Ended = 'ended';

    /** Date limite ou début du match atteints : retour à la cote calculée. */
    case Expired = 'expired';

    /** En attente ou en vigueur : une seule à la fois par sélection. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }
}
