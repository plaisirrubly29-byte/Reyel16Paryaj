<?php

/*
| Module Identity & Access.
| Les valeurs réglementaires (âge minimum, etc.) suivent la décision D-01/D-09 (docs/decisions.md).
*/

return [

    // Âge minimum pour ouvrir un compte (années).
    'minimum_age' => (int) env('IDENTITY_MINIMUM_AGE', 18),

    // V2-E01 : version en vigueur des textes d'information (conditions, confidentialité, règlement des paris, jeu
    // responsable), acceptée à l'inscription. Doit rester égale à LEGAL_VERSION du frontend (features/legal/legal.ts) :
    // tout changement de fond d'un texte = nouvelle version des deux côtés.
    'terms_version' => env('IDENTITY_TERMS_VERSION', '2026-11-02'),

    // Limitation des tentatives de connexion, sur le numéro normalisé.
    'login' => [
        'max_attempts_per_phone' => 5, // par minute, par couple téléphone + IP
        'max_attempts_per_ip' => 20, // par minute, toutes adresses confondues
        'max_attempts_per_account' => 30, // par heure, par téléphone, quelle que soit l'IP
    ],

    // Limite globale de l'API pour un compte connecté (revue P8-02, F12) : requêtes par minute, par compte et jamais par
    // IP (un cybercafé partage la même adresse). Les visiteurs n'y sont pas soumis : leurs routes ont leurs limites.
    'api_rate_per_minute' => (int) env('API_ACCOUNT_RATE_PER_MINUTE', 120),

    // Double authentification, par compte (un code TOTP = 10^6 combinaisons).
    'mfa' => [
        'max_attempts_per_minute' => 5,
        'max_attempts_per_hour' => 20,
        'max_attempts_per_day' => 50,
        'max_failures_before_logout' => 5, // échecs consécutifs de mfa/verify
    ],

    // Sessions (V2-C06, revue P8-02 F12). En plus de SESSION_LIFETIME (inactivité), une durée maximale depuis la
    // connexion, même si le compte reste actif ; et, pour l'administration, un nouveau code MFA après inactivité.
    'session' => [
        'player_max_minutes' => (int) env('IDENTITY_PLAYER_SESSION_MAX_MINUTES', 720), // 12 h
        'admin_max_minutes' => (int) env('IDENTITY_ADMIN_SESSION_MAX_MINUTES', 480), // 8 h
        'admin_mfa_idle_minutes' => (int) env('IDENTITY_ADMIN_MFA_IDLE_MINUTES', 30),
    ],

    // M3 (décision D-07) : un rôle attribué par un administrateur ne permet de valider une demande d'un autre
    // (*.approve) qu'après ce délai. Un rôle donné depuis le serveur (identity:grant-role) n'en a pas.
    'approval_role_min_age_hours' => (int) env('IDENTITY_APPROVAL_ROLE_MIN_AGE_HOURS', 24),

    // Numéro de téléphone haïtien local : identifiant de connexion (voir PhoneNumber::normalize).
    'phone' => [
        'country_code' => '509',
        'length' => 8,
    ],

];
