<?php

namespace App\Modules\Betting\Enums;

enum BetSelectionStatus: string
{
    case Pending = 'pending';

    case Won = 'won';

    case Lost = 'lost';

    /** Sélection annulée : sa cote compte pour 1 dans un combiné — politique D-05. */
    case Void = 'void';
}
