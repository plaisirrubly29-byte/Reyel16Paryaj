<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * Erreur métier destinée au client de l'API.
 *
 * Rendue par ApiErrorRenderer au format unique (voir docs/api/README.md) :
 * { "error": { "code": "...", "message": "...", "details": {...} } }
 *
 * Exemple : throw new ApiException('odds_changed', 'La cote a changé.', 409, ['odds' => '2.15']);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  string  $errorCode  identifiant stable en snake_case, utilisable par le frontend
     * @param  array<string, mixed>  $details  informations complémentaires sûres à exposer
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
