<?php

namespace App\Modules\IdentityAccess\Enums;

use App\Support\Http\ApiException;

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

    /** Refus d'accès d'un compte qui ne peut pas se connecter : message dédié à l'auto-exclusion. */
    public function accessDenied(): ApiException
    {
        return $this === self::Excluded
            ? new ApiException('account_excluded', __('Vous avez demandé une auto-exclusion : ce compte est fermé au jeu. Pour le rouvrir après son échéance, contactez le support.'), 423)
            : new ApiException('account_suspended', __('Ce compte est suspendu ou fermé. Contactez le support.'), 423);
    }
}
