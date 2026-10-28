<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de l'API (revue P8-02, F13).
 *
 * L'API ne renvoie que du JSON : défense en profondeur (aucun contenu actif, jamais dans une iframe,
 * pas de Referer). Les en-têtes des pages du frontend se posent sur le reverse proxy qui le sert (B3).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Version de PHP révélée par le serveur ; en production, expose_php = Off dans php.ini fait de même.
        header_remove('X-Powered-By');

        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);

        // HSTS seulement en HTTPS : derrière le reverse proxy, isSecure() suppose les proxies déclarés (B2).
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
