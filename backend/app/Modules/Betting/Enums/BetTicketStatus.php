<?php

namespace App\Modules\Betting\Enums;

enum BetTicketStatus: string
{
    /** Accepté, en attente des résultats. */
    case Pending = 'pending';

    case Won = 'won';

    case Lost = 'lost';

    /** Annulé et remboursé (match annulé, reporté…) — politique D-05. */
    case Void = 'void';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
