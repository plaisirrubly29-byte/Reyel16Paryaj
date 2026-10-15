<?php

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Support\Http\ApiException;
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

            throw new ApiException('account_suspended', __('Ce compte est suspendu ou fermé. Contactez le support.'), 423);
        }

        return $next($request);
    }
}
