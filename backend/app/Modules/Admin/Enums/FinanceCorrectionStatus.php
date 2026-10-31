<?php

namespace App\Modules\Admin\Enums;

enum FinanceCorrectionStatus: string
{
    /** Demandée, en attente du second administrateur. */
    case Pending = 'pending';

    /** Validée : l'écriture compensatoire est passée. */
    case Approved = 'approved';

    /** Refusée : aucune écriture. */
    case Rejected = 'rejected';
}
