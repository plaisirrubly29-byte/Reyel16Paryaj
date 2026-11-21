<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durée maximale d'une session depuis la connexion, même si le compte reste actif (V2-C06, revue P8-02 F12) :
 * 12 h pour un joueur, 8 h pour un compte administratif (config/identity.php). SESSION_LIFETIME ne borne que
 * l'inactivité. Au-delà, la session est fermée et la requête continue sans compte : une route protégée répond 401,
 * comme après une déconnexion. Branché sur le groupe "api", après le démarrage de la session.
 */
class EnforceSessionLifetime
{
    /** Horodatage (secondes) de la connexion, posé par AuthController. */
    public const STARTED_AT = 'auth_started_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || Auth::guard('web')->guest()) {
            return $next($request);
        }

        $session = $request->session();
        $startedAt = $session->get(self::STARTED_AT);

        // Session ouverte avant cette règle : la durée part de maintenant.
        if (! is_int($startedAt)) {
            $session->put(self::STARTED_AT, now()->getTimestamp());

            return $next($request);
        }

        $user = Auth::guard('web')->user();
        $maxMinutes = (int) config($user->isAdmin() ? 'identity.session.admin_max_minutes' : 'identity.session.player_max_minutes');

        if (now()->getTimestamp() - $startedAt > $maxMinutes * 60) {
            Auth::guard('web')->logout();
            // Le garde sanctum (par défaut) a déjà mémorisé le compte plus tôt dans la requête (AuthenticateSession).
            Auth::guard('sanctum')->forgetUser();
            $session->invalidate();
            $session->regenerateToken();
        }

        return $next($request);
    }
}
