<?php

/*
| Module Identity & Access.
| Les valeurs réglementaires (âge minimum, etc.) suivent la décision D-01/D-09 (docs/decisions.md).
*/

return [

    // Âge minimum pour ouvrir un compte (années).
    'minimum_age' => (int) env('IDENTITY_MINIMUM_AGE', 18),

    // Bêta fermée (V2-F07) : codes d'invitation acceptés à l'inscription, séparés par des virgules (majuscules ou non).
    // Vide (défaut) : inscription ouverte à tous.
    'invite_codes' => array_values(array_filter(array_map(
        fn (string $code) => strtoupper(trim($code)),
        explode(',', (string) env('REGISTRATION_INVITE_CODES', '')),
    ))),

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
    // IP (un cybercafé partage la même adresse).
    'api_rate_per_minute' => (int) env('API_ACCOUNT_RATE_PER_MINUTE', 120),

    // Limite globale pour un visiteur non connecté (D-17, PS-5) : requêtes par minute, par IP, sur toute l'API, en plus
    // des limites de chaque route. Large à cause des adresses partagées (cybercafés). 0 = sans limite (urgence).
    // Derrière Cloudflare, TRUSTED_PROXIES doit être réglé, sinon tous les visiteurs semblent venir de quelques IP.
    'api_guest_rate_per_minute' => (int) env('IDENTITY_API_GUEST_RATE_PER_MINUTE', 300),

    // Vérification « humain » par Cloudflare Turnstile (D-17, PS-3/PS-4/PS-7) : inscription, mot de passe oublié, et
    // connexion après plusieurs échecs. Désactivée tant que la clé secrète est vide (développement, tests, Staging
    // avant les écrans #193).
    'human_verification' => [
        'turnstile_secret_key' => env('TURNSTILE_SECRET_KEY', ''),
        // Noms d'hôte acceptés pour le jeton (le domaine du site), séparés par des virgules ; vide = non vérifié
        // (clés de test de Cloudflare). À renseigner en Production.
        'allowed_hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURNSTILE_ALLOWED_HOSTNAMES', ''))))),
        'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 3),
        // Connexion : vérification exigée après ce nombre d'échecs, par identifiant saisi ou par IP, sur la période.
        'login_failures_before_challenge' => 3,
        'login_failure_window_minutes' => 15,
    ],

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
