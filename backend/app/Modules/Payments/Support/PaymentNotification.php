<?php

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\PaymentStatus;

/** Webhook d'un prestataire traduit dans le format commun. */
final readonly class PaymentNotification
{
    public function __construct(
        /** Identifiant unique de l'événement chez le prestataire : un même événement rejoué n'a qu'un effet. */
        public string $eventId,
        /** Notre référence (payment_transactions.reference). */
        public string $reference,
        public ?string $providerReference,
        /** Pending, Succeeded ou Failed. */
        public PaymentStatus $status,
        public int $amountMinor,
        public string $currency,
    ) {}
}
