<?php

namespace App\Modules\Catalogue\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache court des réponses du catalogue public (test de charge P8-03) : identiques pour tous les visiteurs anonymes,
 * elles sont servies depuis le cache (Redis, partagé par les processus php-fpm) pendant
 * sports_data.catalogue_cache_seconds secondes au lieu d'être recalculées à chaque requête. 0 = désactivé.
 *
 * Seules les réponses 200 des requêtes GET sont gardées, une entrée par URL complète (paramètres triés). À placer
 * après la limite de débit, qui reste comptée par visiteur. L'en-tête X-Catalogue-Cache vaut hit ou miss.
 */
class CacheCatalogueResponse
{
    private const PREFIX = 'catalogue:response:';

    public function handle(Request $request, Closure $next): Response
    {
        $seconds = (int) config('sports_data.catalogue_cache_seconds');
        if ($seconds <= 0 || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $query = $request->query();
        ksort($query);
        $key = self::PREFIX.sha1($request->path().'?'.http_build_query($query));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return response($cached['body'], 200, ['Content-Type' => $cached['type'], 'X-Catalogue-Cache' => 'hit']);
        }

        $response = $next($request);
        if ($response->getStatusCode() === 200) {
            Cache::put($key, ['body' => $response->getContent(), 'type' => $response->headers->get('Content-Type')], $seconds);
        }
        $response->headers->set('X-Catalogue-Cache', 'miss');

        return $response;
    }
}
