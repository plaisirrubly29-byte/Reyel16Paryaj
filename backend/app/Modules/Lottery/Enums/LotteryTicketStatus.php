<?php

namespace App\Modules\Lottery\Enums;

/** Statut d'une fiche ou d'une ligne de borlette. */
enum LotteryTicketStatus: string
{
    /** Vendue, en attente du résultat du tirage. */
    case Pending = 'pending';

    /** Fiche : au moins une ligne gagnante. Ligne : gagnante. */
    case Won = 'won';

    case Lost = 'lost';

    /** Tirage sans résultat, ou jeu impossible à juger (pick 3 absent) : mise remboursée. */
    case Void = 'void';
}
