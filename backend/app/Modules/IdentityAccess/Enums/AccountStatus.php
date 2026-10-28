<?php

namespace App\Modules\IdentityAccess\Enums;

/**
 * Statut d'un compte. Seul un compte actif peut se connecter et parier.
 */
enum AccountStatus: string
{
    case Active = 'active';

    /** Suspendu par un administrateur (motif audité). */
    case Suspended = 'suspended';

    /** Auto-exclusion du joueur (jeu responsable, P7-02). */
    case Excluded = 'excluded';

    /** Fermé définitivement. */
    case Closed = 'closed';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
