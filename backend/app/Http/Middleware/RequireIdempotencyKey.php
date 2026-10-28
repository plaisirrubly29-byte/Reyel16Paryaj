<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * En-tête Idempotency-Key obligatoire sur les routes à effet (alias de route : "idempotent").
 *
 * - même clé + même requête  → réponse d'origine rejouée (Idempotent-Replayed: true), rien n'est recréé ;
 * - même clé + requête différente → 409 idempotency_key_reused ;
 * - requête identique encore en cours → 409 idempotency_request_in_progress.
 *
 * Seules les réponses 2xx sont mémorisées : une erreur (validation, cote changée…) libère la clé
 * pour que le client puisse corriger sa requête et réessayer.
 * L'unicité (scope, key) en base garantit qu'une seule requête concurrente s'exécute.
 */
class RequireIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    /** Au-delà, une clé restée "processing" (processus tué) est considérée abandonnée. */
    public const STALE_AFTER_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null || $key === '') {
            throw new ApiException('precondition_required', __('En-tête Idempotency-Key obligatoire.'), 428);
        }

        if (! Str::isUuid($key)) {
            throw new ApiException('idempotency_key_invalid', __("L'en-tête Idempotency-Key doit être un UUID."), 400);
        }

        $scope = $request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'guest:'.$request->ip();
        $fingerprint = $this->fingerprint($request);

        [$record, $reserved] = $this->reserve($scope, strtolower($key), $request, $fingerprint);

        if (! $reserved) {
            return $this->replay($record, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'response_content_type' => $response->headers->get('Content-Type'),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }

    /**
     * Réserve la clé de façon atomique (INSERT … ON CONFLICT DO NOTHING).
     *
     * @return array{0: IdempotencyKey, 1: bool} la ligne, et true si cette requête l'a réservée
     */
    private function reserve(string $scope, string $key, Request $request, string $fingerprint, int $attempt = 0): array
    {
        $now = now();

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'scope' => $scope,
            'key' => $key,
            'method' => $request->method(),
            'path' => $request->path(),
            'fingerprint' => $fingerprint,
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = IdempotencyKey::where(['scope' => $scope, 'key' => $key])->first();

        if ($inserted === 1 && $record !== null) {
            return [$record, true];
        }

        // Ligne supprimée entre-temps (requête précédente en erreur) ou abandonnée : on retente la réservation.
        if (($record === null || $this->isStale($record)) && $attempt < 2) {
            $record?->delete();

            return $this->reserve($scope, $key, $request, $fingerprint, $attempt + 1);
        }

        if ($record === null) {
            throw new ApiException('idempotency_request_in_progress', __('Une requête identique est en cours de traitement.'), 409, [], ['Retry-After' => '1']);
        }

        return [$record, false];
    }

    private function replay(IdempotencyKey $record, string $fingerprint): Response
    {
        if (! hash_equals($record->fingerprint, $fingerprint)) {
            throw new ApiException('idempotency_key_reused', __('Cette clé d\'idempotence a déjà été utilisée pour une autre requête.'), 409);
        }

        if ($record->status !== IdempotencyKey::STATUS_COMPLETED) {
            throw new ApiException('idempotency_request_in_progress', __('Une requête identique est en cours de traitement.'), 409, [], ['Retry-After' => '1']);
        }

        return new Response($record->response_body, $record->response_status, array_filter([
            'Content-Type' => $record->response_content_type,
            'Idempotent-Replayed' => 'true',
        ]));
    }

    private function isStale(IdempotencyKey $record): bool
    {
        return $record->status === IdempotencyKey::STATUS_PROCESSING
            && $record->created_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    private function fingerprint(Request $request): string
    {
        $payload = $request->all();
        $this->sortRecursive($payload);

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload));
    }

    /** @param array<mixed> $data */
    private function sortRecursive(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}
