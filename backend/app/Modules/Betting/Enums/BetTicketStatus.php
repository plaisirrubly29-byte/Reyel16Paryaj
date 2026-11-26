<?php

namespace App\Modules\Betting\Enums;

enum BetTicketStatus: string
{
    /** Pari en direct : mise réservée, revérifié à la fin du délai d'acceptation (D-20). */
    case Awaiting = 'awaiting';

    /** Accepté, en attente des résultats. */
    case Pending = 'pending';

    case Won = 'won';

    case Lost = 'lost';

    /** Annulé et remboursé (match annulé, reporté…) — politique D-05. */
    case Void = 'void';

    /** Pari en direct refusé à la fin du délai d'acceptation (cote, score, marché suspendu) : mise rendue (D-20). */
    case Rejected = 'rejected';

    public function isSettled(): bool
    {
        return ! in_array($this, [self::Awaiting, self::Pending], true);
    }
}
