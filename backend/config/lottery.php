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

    /*
    | Vente des fiches (V2-D09, décision D-12 du 2026-11-26). Montants en unité mineure (100 = 1,00 HTG).
    | Le barème et les limites changent ici, jamais dans le code ; ils sont figés sur chaque fiche à la vente.
    */
    'sales' => [
        // Interrupteur de la vente : false = GET /lottery/games renvoie sales_open false, POST /lottery/tickets 403.
        'enabled' => (bool) env('LOTTERY_SALES_ENABLED', false),

        // Fin des ventes : heure du tirage moins ce nombre de minutes (L-1).
        'close_before_minutes' => (int) env('LOTTERY_CLOSE_BEFORE_MINUTES', 5),

        'max_plays_per_ticket' => (int) env('LOTTERY_MAX_PLAYS_PER_TICKET', 20),

        // Gain possible maximal par combinaison jouée (jeu, option, numéros), par tirage et par date (L-2).
        'max_exposure_per_number_minor' => (int) env('LOTTERY_MAX_EXPOSURE_PER_NUMBER_MINOR', 25_000_000),

        // Gain versé plafonné par fiche (R-5, même plafond que les paris sportifs).
        'max_payout_per_ticket_minor' => (int) env('LOTTERY_MAX_PAYOUT_PER_TICKET_MINOR', 100_000_000),

        // Tirage sans résultat publié après ce délai : fiche annulée et mise remboursée (L-5).
        'void_after_hours' => (int) env('LOTTERY_VOID_AFTER_HOURS', 48),

        // Version du barème, figée sur chaque fiche.
        'rules_version' => '2026-11-26',

        /*
        | Barème haïtien courant : multiplicateurs de la mise, en chaînes (jamais de float).
        | payouts : clé = lot (1, 2, 3) pour la bolèt, 0 pour les autres jeux (un seul multiplicateur).
        | - bolet : une boule de 2 chiffres ; un seul gain par ligne, au meilleur lot sorti (R-3).
        | - maryaj : deux boules sorties parmi les trois lots, dans n'importe quel ordre (R-1).
        | - loto3 : les 3 chiffres du premier numéro (pick 3).
        | - loto4 : option 1 = lot 1 + lot 2, option 2 = lot 2 + lot 3, option 3 = lot 1 + lot 3 (R-2).
        | - loto5 : pick 3 suivi du lot 2 (option 1) ou du lot 3 (option 2) (R-2).
        | Un jeu retiré de cette liste n'est plus vendu.
        */
        'games' => [
            'bolet' => ['digits' => 2, 'count' => 1, 'options' => [], 'min_stake_minor' => 500, 'max_stake_minor' => 500_000, 'payouts' => [1 => '50', 2 => '20', 3 => '10']],
            'maryaj' => ['digits' => 2, 'count' => 2, 'options' => [], 'min_stake_minor' => 500, 'max_stake_minor' => 500_000, 'payouts' => [0 => '1000']],
            'loto3' => ['digits' => 3, 'count' => 1, 'options' => [], 'min_stake_minor' => 500, 'max_stake_minor' => 500_000, 'payouts' => [0 => '500']],
            'loto4' => ['digits' => 4, 'count' => 1, 'options' => [1, 2, 3], 'min_stake_minor' => 500, 'max_stake_minor' => 500_000, 'payouts' => [0 => '5000']],
            'loto5' => ['digits' => 5, 'count' => 1, 'options' => [1, 2], 'min_stake_minor' => 500, 'max_stake_minor' => 500_000, 'payouts' => [0 => '25000']],
        ],
    ],

];
