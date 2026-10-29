<?php

namespace App\Modules\Compliance\Exceptions;

use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Support\Http\ApiException;

/** Pari refusé au titre du jeu responsable (pause, limite de mise) : 422, journalisé par l'appelant. */
class ResponsibleGamingException extends ApiException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly ResponsiblePlayEventType $event,
        string $errorCode,
        string $message,
        array $details = [],
    ) {
        parent::__construct($errorCode, $message, 422, $details);
    }
}
