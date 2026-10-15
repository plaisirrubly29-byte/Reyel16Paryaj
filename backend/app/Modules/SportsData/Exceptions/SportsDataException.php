<?php

namespace App\Modules\SportsData\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Échec d'accès au fournisseur. La raison permet à la synchronisation de réagir
 * (ex. suspendre les marchés si le fournisseur est indisponible — P3-04).
 */
class SportsDataException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';        // panne, délai dépassé, erreur 5xx

    public const UNAUTHORIZED = 'unauthorized';      // clé absente ou invalide

    public const QUOTA_EXHAUSTED = 'quota_exhausted'; // quota épuisé ou garde-fou atteint

    public const INVALID_RESPONSE = 'invalid_response'; // réponse illisible ou non conforme

    public const NOT_FOUND = 'not_found';            // sport inconnu ou hors saison

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
