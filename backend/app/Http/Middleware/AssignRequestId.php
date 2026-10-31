<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de corrélation de chaque requête (P1-04).
 *
 * Reprend l'en-tête X-Request-Id s'il est sûr (proxy, frontend), sinon en génère un.
 * Placé dans Context : Laravel l'ajoute à chaque log et le transmet aux jobs mis en queue.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->incomingId($request) ?? (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Identifiant sûr : refuse les valeurs arbitraires (injection dans les logs, taille : 64 comme audit_logs.request_id).
     * « D » : « $ » n'accepte pas un retour à la ligne final (revue P8-02).
     */
    public static function isSafe(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $id) === 1;
    }

    private function incomingId(Request $request): ?string
    {
        $id = $request->headers->get(self::HEADER);

        return self::isSafe($id) ? $id : null;
    }
}
