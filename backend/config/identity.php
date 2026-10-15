<?php

/*
| Module Identity & Access.
| Les valeurs réglementaires (âge minimum, etc.) suivent la décision D-01/D-09 (docs/decisions.md).
*/

return [

    // Âge minimum pour ouvrir un compte (années).
    'minimum_age' => (int) env('IDENTITY_MINIMUM_AGE', 18),

    // Limitation des tentatives de connexion.
    'login' => [
        'max_attempts_per_email' => 5, // par minute, par couple email + IP
        'max_attempts_per_ip' => 20, // par minute, toutes adresses confondues
    ],

];
