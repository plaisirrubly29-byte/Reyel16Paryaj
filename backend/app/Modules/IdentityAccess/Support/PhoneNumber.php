<?php

namespace App\Modules\IdentityAccess\Support;

/**
 * Numéro de téléphone haïtien local, identifiant de connexion.
 *
 * Une seule normalisation pour l'inscription, la connexion et les limiteurs de tentatives :
 * "34123456", "3412-3456" et "+509 34 12 34 56" désignent le même compte.
 */
final class PhoneNumber
{
    /**
     * Garde uniquement les chiffres, puis retire l'indicatif "509" s'il est présent
     * (saisie du type "+509 34 12 34 56" ou "509-3412-3456").
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '509')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }
}
