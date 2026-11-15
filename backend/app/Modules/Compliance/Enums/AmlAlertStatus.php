<?php

namespace App\Modules\Compliance\Enums;

enum AmlAlertStatus: string
{
    /** À examiner par la Conformité. */
    case Open = 'open';

    /** Classée : fausse alerte ou activité justifiée. */
    case Dismissed = 'dismissed';

    /** Signalée : soupçon, à déclarer. */
    case Reported = 'reported';
}
