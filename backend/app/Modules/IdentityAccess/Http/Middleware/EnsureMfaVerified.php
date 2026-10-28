<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Services\MfaService;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte administratif doit avoir activé le MFA et l'avoir validé dans la session courante.
 * Alias : "mfa". À placer sur toutes les routes /admin, après auth:sanctum.
 */
class EnsureMfaVerified
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            return $next($request);
        }

        if (! $this->mfa->isEnabled($user)) {
            throw new ApiException('mfa_setup_required', __('Activez la double authentification pour accéder à l\'administration.'), 403);
        }

        if (! $this->mfa->isVerified($user, $request->hasSession() ? $request->session() : null)) {
            throw new ApiException('mfa_required', __('Saisissez votre code de double authentification.'), 403);
        }

        return $next($request);
    }
}
