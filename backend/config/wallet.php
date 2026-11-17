<?php

/*
| Module Wallet & Ledger.
| Devise, arrondis et limites : décision D-06 (tâche P0-05), validée le 2026-10-29.
*/

return [

    // Devise unique de la V1 (ISO 4217).
    'currency' => env('APP_CURRENCY', 'HTG'),

    // Fonds fictifs (V1) : crédit de bienvenue possible et portefeuille marqué « simulé » dans GET /wallet.
    // false dès que l'argent est réel (dépôts Plop Plop, V2-B04) : plus aucun crédit fictif ne peut être écrit.
    'simulated' => (bool) env('WALLET_SIMULATED', true),

    // Crédit offert à l'inscription, en unité mineure (500 000 = 5 000,00 HTG). 0 (défaut) désactive le crédit.
    'welcome_credit_minor' => (int) env('WALLET_WELCOME_CREDIT_MINOR', 0),

];
