<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie une permission côté API. Alias : "permission".
 *
 *   ->middleware('permission:users.suspend')               // permission requise
 *   ->middleware('permission:audit.view|ledger.view')      // l'une des permissions
 *
 * À placer après auth:sanctum. Le masquage dans l'interface ne remplace jamais ce contrôle.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        foreach (explode('|', $permissions) as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        throw new AuthorizationException;
    }
}
