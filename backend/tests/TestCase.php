<?php

namespace Tests;

use App\Models\User;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionLifetime;
use App\Modules\IdentityAccess\Services\MfaService;
use App\Modules\IdentityAccess\Services\Totp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Connecte un administrateur dont le MFA est activé et validé pour la session (accès aux routes /admin).
     */
    protected function actingAsVerifiedAdmin(User $admin): static
    {
        if ($admin->mfa_confirmed_at === null) {
            $admin->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();
        }

        // Origin du frontend : Sanctum n'ouvre la session (mode SPA) que pour les requêtes first-party.
        // Session ouverte et code MFA saisi « maintenant » : un test qui avance l'horloge (travel) reste dans la durée
        // maximale de la session et dans le délai d'inactivité du MFA (V2-C06).
        return $this->actingAs($admin)
            ->withHeader('Origin', config('app.frontend_url'))
            ->withSession([
                MfaService::SESSION_KEY => $admin->getKey(),
                MfaService::LAST_ACTIVITY_KEY => now()->getTimestamp(),
                EnforceSessionLifetime::STARTED_AT => now()->getTimestamp(),
            ]);
    }
}
