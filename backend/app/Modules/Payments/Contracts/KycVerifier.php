<?php

namespace App\Modules\Payments\Contracts;

use App\Models\User;

/**
 * KYC exigé avant un retrait (contrat V2-B03), contrôlé seulement si PAYMENTS_WITHDRAWALS_REQUIRE_KYC=true.
 * Le module KYC (V2-C03) fournira l'implémentation ; en attendant, PendingKycVerifier refuse tout le monde.
 */
interface KycVerifier
{
    public function isVerified(User $user): bool;
}
