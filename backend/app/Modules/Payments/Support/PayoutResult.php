<?php

namespace App\Modules\Payments\Support;

/** Résultat d'un versement chez le prestataire. */
final readonly class PayoutResult
{
    /** Versé au joueur. */
    public const PAID = 'paid';

    /** Refusé définitivement (numéro invalide…) : le montant est rendu au joueur. */
    public const REJECTED = 'rejected';

    /** Envoyé mais résultat inconnu (délai dépassé, en cours) : vérifié plus tard par la référence. */
    public const UNKNOWN = 'unknown';

    /** Pas exécuté (attente obligatoire, solde marchand insuffisant, configuration) : réessayé plus tard. */
    public const RETRY_LATER = 'retry_later';

    public function __construct(
        public string $outcome,
        public ?string $providerReference = null,
        public ?int $feeMinor = null,
        /** Explication interne (journal, admin) ; jamais affichée telle quelle au joueur. */
        public ?string $message = null,
        /** Référence du transfert chez MonCash / NatCash (api_reference), gardée pour le support. */
        public ?string $externalReference = null,
    ) {}

    public static function paid(?string $providerReference, ?int $feeMinor = null, ?string $externalReference = null): self
    {
        return new self(self::PAID, $providerReference, $feeMinor, externalReference: $externalReference);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, message: $message);
    }

    public static function unknown(string $message): self
    {
        return new self(self::UNKNOWN, message: $message);
    }

    public static function retryLater(string $message): self
    {
        return new self(self::RETRY_LATER, message: $message);
    }
}
