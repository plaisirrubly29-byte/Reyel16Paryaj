<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Contracts\HumanVerifier;
use App\Modules\IdentityAccess\Enums\HumanVerificationResult;
use App\Modules\IdentityAccess\Services\LoginFailureCounter;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérification « humain » (Cloudflare Turnstile, D-17) sur les formulaires visés par les robots. Alias : "human".
 *
 *   ->middleware('human:always')               // inscription, mot de passe oublié : toujours
 *   ->middleware('human:after-login-failures') // connexion : après plusieurs échecs (LoginFailureCounter)
 *
 * Placé avant la validation du formulaire : sans jeton valide, aucune autre erreur n'est renvoyée (pas d'énumération
 * des comptes par « numéro déjà utilisé »). Désactivé tant que la clé secrète n'est pas configurée.
 * Fournisseur injoignable (PS-7) : « always » refuse (sécurité d'abord) ; la connexion passe, ses propres limites de
 * fréquence restant appliquées (décision du 2026-11-19). Dans les deux cas, l'alerte d'exploitation se déclenche.
 */
class RequireHumanVerification
{
    public const ALWAYS = 'always';

    public const AFTER_LOGIN_FAILURES = 'after-login-failures';

    public function __construct(
        private readonly HumanVerifier $verifier,
        private readonly LoginFailureCounter $failures,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = self::ALWAYS): Response
    {
        if (! $this->verifier->enabled()) {
            return $next($request);
        }

        $loginMode = $mode === self::AFTER_LOGIN_FAILURES;
        if ($loginMode && ! $this->failures->requiresChallenge(LoginFailureCounter::identifierFrom($request), $request->ip())) {
            return $next($request);
        }

        $token = $request->input('captcha_token');
        if (! is_string($token) || $token === '') {
            throw $this->failed('required', __('Confirmez que vous n\'êtes pas un robot.'));
        }

        return match ($this->verifier->verify($token, $request->ip())) {
            HumanVerificationResult::Valid => $next($request),
            HumanVerificationResult::Invalid => throw $this->failed('invalid', __('La vérification anti-robot a échoué ou a expiré. Recommencez-la.')),
            HumanVerificationResult::Unavailable => $loginMode
                ? $next($request)
                : throw $this->failed('unavailable', __('La vérification anti-robot est momentanément indisponible. Réessayez dans quelques minutes.')),
        };
    }

    private function failed(string $reason, string $message): ApiException
    {
        return new ApiException('captcha_failed', $message, 422, ['reason' => $reason]);
    }
}
