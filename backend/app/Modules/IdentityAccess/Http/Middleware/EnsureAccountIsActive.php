<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe immédiatement l'accès d'un compte suspendu, exclu ou fermé, même avec une session ouverte.
 * Alias : "active". À placer après auth:sanctum.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->status->canAuthenticate()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw $user->status->accessDenied();
        }

        return $next($request);
    }
}
