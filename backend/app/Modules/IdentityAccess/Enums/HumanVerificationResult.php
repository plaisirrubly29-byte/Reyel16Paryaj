<?php

namespace App\Modules\IdentityAccess\Enums;

/** Résultat d'une vérification « humain » (Turnstile, D-17). */
enum HumanVerificationResult: string
{
    /** Jeton accepté. */
    case Valid = 'valid';

    /** Jeton refusé, expiré, déjà utilisé, ou émis pour un autre site. */
    case Invalid = 'invalid';

    /** Fournisseur injoignable ou réponse illisible : rien n'est prouvé. */
    case Unavailable = 'unavailable';
}
