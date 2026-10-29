<?php

namespace App\Modules\Compliance\Enums;

enum RestrictionType: string
{
    /** Pause : connexion possible, aucun pari jusqu'à la fin. */
    case Pause = 'pause';

    /** Auto-exclusion : compte « excluded », aucune connexion ; réouverture sur demande après l'échéance. */
    case SelfExclusion = 'self_exclusion';

    /** @return array<string, string> durées proposées, ex. ['24h' => '24 hours'] */
    public function durations(): array
    {
        return config($this === self::Pause ? 'compliance.pause_durations' : 'compliance.exclusion_durations');
    }
}
