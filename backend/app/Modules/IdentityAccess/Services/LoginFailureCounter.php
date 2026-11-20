<?php

namespace App\Modules\IdentityAccess\Services;

use App\Modules\IdentityAccess\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Échecs de connexion récents (D-17, PS-4) : au-delà du seuil, la connexion exige la vérification « humain ».
 *
 * - Comptés par identifiant SAISI (téléphone normalisé ou email en minuscules) et par IP, que le compte existe ou non :
 *   la réponse ne révèle donc jamais si un numéro est inscrit.
 * - L'identifiant n'est gardé qu'en empreinte (sha256), dans le cache (Redis), le temps de la période.
 * - Une connexion réussie remet à zéro les deux compteurs.
 */
class LoginFailureCounter
{
    public function hit(string $identifier, ?string $ip): void
    {
        $decay = (int) config('identity.human_verification.login_failure_window_minutes') * 60;
        foreach ($this->keys($identifier, $ip) as $key) {
            RateLimiter::hit($key, $decay);
        }
    }

    public function clear(string $identifier, ?string $ip): void
    {
        foreach ($this->keys($identifier, $ip) as $key) {
            RateLimiter::clear($key);
        }
    }

    public function requiresChallenge(string $identifier, ?string $ip): bool
    {
        $threshold = (int) config('identity.human_verification.login_failures_before_challenge');

        foreach ($this->keys($identifier, $ip) as $key) {
            if (RateLimiter::attempts($key) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiant saisi, normalisé comme LoginRequest et le limiteur « login » : « 3412-3456 » et « +509 34123456 »
     * comptent ensemble. Lu avant la validation (la vérification a lieu avant elle). Chaîne vide si rien d'exploitable.
     */
    public static function identifierFrom(Request $request): string
    {
        $phone = is_string($request->input('phone')) ? PhoneNumber::normalize($request->input('phone')) : '';
        if ($phone !== '') {
            return 'phone:'.$phone;
        }

        $email = is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : '';

        return $email !== '' ? 'email:'.$email : '';
    }

    /** @return list<string> */
    private function keys(string $identifier, ?string $ip): array
    {
        return array_values(array_filter([
            $identifier !== '' ? 'login-failures:id:'.hash('sha256', $identifier) : null,
            $ip !== null && $ip !== '' ? 'login-failures:ip:'.$ip : null,
        ]));
    }
}
