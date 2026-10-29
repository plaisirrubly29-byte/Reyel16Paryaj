<?php

namespace App\Modules\Settlement\Enums;

/** État d'une demande de correction de résultat (P5-06). */
enum ResultCorrectionStatus: string
{
    case Pending = 'pending';

    /** Validée par un second administrateur : nouvelle version du résultat, tickets réévalués. */
    case Approved = 'approved';

    /** Refusée par un administrateur, ou retirée par son demandeur. */
    case Rejected = 'rejected';
}
