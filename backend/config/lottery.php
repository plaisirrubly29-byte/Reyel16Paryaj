<?php

/*
| Module Lottery — tirages de borlette et résultats publiés, fournis par une API externe.
| Le fournisseur ne donne que les tirages et leurs résultats : la vente et le paiement des tickets restent dans
| la plateforme (étape 2). Les secrets restent dans .env, jamais dans le code ni le frontend.
*/

return [

    // Interrupteur général : tant qu'il est à false, les routes publiques répondent 404 et la synchro ne tourne pas.
    // La borlette figure dans les exclusions par défaut de docs/architecture.md.
    'enabled' => (bool) env('LOTTERY_ENABLED', false),

    // "fake" (développement, tests) ou "unitysoftconnect" (API Borlette UnitySoftConnect, clé dans LOTTERY_API_KEY).
    'provider' => env('LOTTERY_PROVIDER', 'fake'),

    'api' => [
        'url' => env('LOTTERY_API_URL', 'https://unityscgroup.com/api/v1/borlette'),
        'key' => env('LOTTERY_API_KEY'),
        'timeout' => (int) env('LOTTERY_API_TIMEOUT', 20),
    ],

    // Synchronisation des tirages et des résultats (lottery:sync). Toutes les 5 minutes par défaut : un résultat
    // est en général publié quelques minutes après l'heure du tirage.
    'sync_cron' => env('LOTTERY_SYNC_CRON', '*/5 * * * *'),

    // Nombre de jours en arrière relus à chaque synchro (rattrapage après une panne du fournisseur).
    'results_days_back' => (int) env('LOTTERY_RESULTS_DAYS_BACK', 1),

];
