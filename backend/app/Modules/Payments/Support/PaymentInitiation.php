<?php

namespace App\Modules\Payments\Support;

/** Réponse d'un prestataire à l'ouverture d'un paiement. */
final readonly class PaymentInitiation
{
    public function __construct(
        public string $providerReference,
        /** Page du prestataire où le joueur confirme, s'il y en a une. */
        public ?string $redirectUrl = null,
    ) {}
}
