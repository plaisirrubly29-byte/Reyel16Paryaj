<?php

/*
| Module Identity & Access.
| Les valeurs réglementaires (âge minimum, etc.) suivent la décision D-01/D-09 (docs/decisions.md).
*/

return [

    // Âge minimum pour ouvrir un compte (années).
    'minimum_age' => (int) env('IDENTITY_MINIMUM_AGE', 18),

    // Limitation des tentatives de connexion, sur le numéro normalisé.
    'login' => [
        'max_attempts_per_phone' => 5, // par minute, par couple téléphone + IP
        'max_attempts_per_ip' => 20, // par minute, toutes adresses confondues
        'max_attempts_per_account' => 30, // par heure, par téléphone, quelle que soit l'IP
    ],

    // Double authentification, par compte (un code TOTP = 10^6 combinaisons).
    'mfa' => [
        'max_attempts_per_minute' => 5,
        'max_attempts_per_hour' => 20,
        'max_attempts_per_day' => 50,
        'max_failures_before_logout' => 5, // échecs consécutifs de mfa/verify
    ],

    // Numéro de téléphone haïtien local : identifiant de connexion (voir PhoneNumber::normalize).
    'phone' => [
        'country_code' => '509',
        'length' => 8,
    ],

];
