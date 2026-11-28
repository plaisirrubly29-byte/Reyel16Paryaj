<?php

namespace App\Modules\Complaints\Enums;

/** Objet concerné par une réclamation, toujours un objet du joueur. Le lien vers un agent viendra avec V2-G01. */
enum ComplaintLinkType: string
{
    case Bet = 'bet';
    case LedgerEntry = 'ledger_entry';
}
