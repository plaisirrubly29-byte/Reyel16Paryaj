<?php

namespace App\Modules\SportsData\Enums;

/** Seul un marché "open" (et dont la cote est assez récente) accepte des paris. */
enum MarketStatus: string
{
    case Open = 'open';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Settled = 'settled';
}
