<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetService
{
    /**
     * Envoie un lien de réinitialisation si le compte existe.
     * Ne renvoie rien et ne lève rien : le résultat (compte inconnu, envoi limité…) n'est jamais exposé.
     */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => Str::lower($email)]);
    }

    /**
     * Réinitialise le mot de passe avec un jeton à usage unique et ferme toutes les sessions du compte.
     *
     * @throws ApiException invalid_reset_token (jeton invalide, expiré, déjà utilisé ou compte inconnu)
     */
    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => Str::lower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->closeSessions($user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Même erreur pour un compte inconnu et un jeton invalide : pas d'énumération.
            throw new ApiException('invalid_reset_token', __('Ce lien de réinitialisation est invalide ou a expiré.'), 422);
        }
    }

    private function closeSessions(User $user): void
    {
        // Les sessions en base sont supprimées ; pour les autres drivers, AuthenticateSession (Sanctum)
        // déconnecte toute session dont l'empreinte du mot de passe ne correspond plus.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}
