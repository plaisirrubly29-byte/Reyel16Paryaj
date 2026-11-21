<?php

namespace App\Modules\Payments\Support;

/** Statut relu chez le prestataire, avec sa réponse brute (journalisée comme un webhook). */
final readonly class PaymentStatusCheck
{
    public function __construct(
        public PaymentNotification $notification,
        public string $payload,
    ) {}
}
