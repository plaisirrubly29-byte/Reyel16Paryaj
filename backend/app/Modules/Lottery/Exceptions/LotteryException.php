<?php

namespace App\Modules\Lottery\Exceptions;

use RuntimeException;
use Throwable;

/** Échec d'accès au fournisseur de tirages. La raison permet à la synchro et au diagnostic de réagir. */
class LotteryException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';           // panne, délai dépassé, erreur 5xx

    public const UNAUTHORIZED = 'unauthorized';         // clé absente ou invalide

    public const INVALID_RESPONSE = 'invalid_response'; // réponse illisible ou non conforme

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
