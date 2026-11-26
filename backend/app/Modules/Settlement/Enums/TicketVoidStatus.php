<?php

namespace App\Modules\Settlement\Enums;

/** État d'une demande d'annulation de ticket (audit du 2026-11-22, A2). */
enum TicketVoidStatus: string
{
    case Pending = 'pending';

    /** Validée par un second administrateur : sélections à la cote 1, mise rendue. */
    case Approved = 'approved';

    /** Refusée par un administrateur, ou retirée par son demandeur. */
    case Rejected = 'rejected';
}
