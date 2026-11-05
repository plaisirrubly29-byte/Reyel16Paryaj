<?php

namespace App\Modules\IdentityAccess\Enums;

/** Pièces d'identité acceptées (V2-C03), envoyées avec un selfie. */
enum KycDocumentType: string
{
    case Cin = 'cin'; // carte d'identification nationale

    case Passport = 'passport';

    case DrivingLicence = 'driving_licence';

    case Nif = 'nif'; // carte NIF

    /** Le verso porte des informations à vérifier : exigé pour les cartes, pas pour la page photo du passeport. */
    public function requiresBack(): bool
    {
        return $this === self::Cin || $this === self::DrivingLicence;
    }
}
