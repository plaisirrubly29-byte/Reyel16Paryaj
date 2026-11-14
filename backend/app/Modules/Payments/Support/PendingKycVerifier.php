<?php

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Contracts\KycVerifier;

/** Tant que le KYC (V2-C03) n'est pas branché, aucun compte n'est vérifié : avec le contrôle activé, aucun retrait. */
class PendingKycVerifier implements KycVerifier
{
    public function isVerified(User $user): bool
    {
        return false;
    }
}
