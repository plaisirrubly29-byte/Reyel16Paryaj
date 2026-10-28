<?php

namespace App\Modules\Settlement\Enums;

enum ResultStatus: string
{
    /** Match terminé, score final connu. */
    case Final = 'final';

    /** Match annulé (ou abandonné sans résultat) : les sélections sont annulées — politique D-05. */
    case Cancelled = 'cancelled';
}
