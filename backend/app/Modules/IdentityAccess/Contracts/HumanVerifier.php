<?php

namespace App\Modules\IdentityAccess\Contracts;

use App\Modules\IdentityAccess\Enums\HumanVerificationResult;

/**
 * Vérification qu'une requête vient d'un humain (D-17, PS-3). Implémentation : Cloudflare Turnstile ; une autre
 * (hCaptcha…) ne toucherait que sa classe. Réutilisable pour l'envoi des codes SMS (V2-C01, D-17 point 4).
 */
interface HumanVerifier
{
    /** false tant que la clé secrète n'est pas configurée : aucune vérification n'est alors exigée. */
    public function enabled(): bool;

    public function verify(string $token, ?string $ip): HumanVerificationResult;

    /** Dernière fois que le fournisseur était injoignable (alerte d'exploitation, PS-7), ou null. */
    public function lastUnavailableAt(): ?string;
}
