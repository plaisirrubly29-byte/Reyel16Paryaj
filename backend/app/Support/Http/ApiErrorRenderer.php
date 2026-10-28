<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Convertit toute exception levée sur /api/* en réponse JSON au format unique.
 *
 * Les messages internes ne sont jamais exposés pour les erreurs 5xx (sauf APP_DEBUG).
 */
final class ApiErrorRenderer
{
    /** Codes d'erreur génériques par statut HTTP. */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        423 => 'locked',
        428 => 'precondition_required',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    public function __invoke(Throwable $e): JsonResponse
    {
        // Laravel convertit ces exceptions en exceptions HTTP avant de nous les passer ;
        // on repart de l'original pour ne pas exposer son message (nom de modèle, motif interne).
        $previous = $e->getPrevious();
        if ($e instanceof HttpExceptionInterface
            && ($previous instanceof AuthorizationException || $previous instanceof ModelNotFoundException || $previous instanceof RecordsNotFoundException)) {
            $e = $previous;
        }

        return match (true) {
            $e instanceof ApiException => self::response($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers),
            $e instanceof ValidationException => self::response(422, 'validation_failed', __('Les données envoyées sont invalides.'), $e->errors()),
            $e instanceof AuthenticationException => self::response(401, 'unauthenticated', __('Authentification requise.')),
            $e instanceof AuthorizationException => self::response(403, 'forbidden', __('Action non autorisée.')),
            $e instanceof ModelNotFoundException, $e instanceof RecordsNotFoundException => self::response(404, 'not_found', __('Ressource introuvable.')),
            $e instanceof ThrottleRequestsException => self::response(429, 'too_many_requests', __('Trop de tentatives. Réessayez plus tard.'), [], $e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::response(500, 'server_error', config('app.debug') ? $e->getMessage() : __('Erreur interne du serveur.')),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'http_error');

        // Les messages des exceptions HTTP 4xx sont écrits par nous (abort(404, '...')) ; les 5xx restent génériques.
        $message = $status < 500 && $e->getMessage() !== ''
            ? $e->getMessage()
            : ($status >= 500 && ! config('app.debug') ? __('Erreur interne du serveur.') : ($e->getMessage() ?: $code));

        return self::response($status, $code, $message, [], $e->getHeaders());
    }
}
