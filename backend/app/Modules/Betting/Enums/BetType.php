<?php

namespace App\Modules\Betting\Enums;

/** Pari simple (une sélection) ou combiné (plusieurs) — décision D-04. */
enum BetType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
