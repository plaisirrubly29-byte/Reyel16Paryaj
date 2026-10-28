<?php

namespace App\Modules\SportsData\Enums;

enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Finished = 'finished';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';
}
