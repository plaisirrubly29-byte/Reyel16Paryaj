<?php

/*
| Module Wallet & Ledger.
| Devise, arrondis et limites : décision D-06 (tâche P0-05), validée le 2026-10-29.
*/

return [

    // Devise unique de la V1 (ISO 4217).
    'currency' => env('APP_CURRENCY', 'HTG'),

    // V1 : aucun prestataire de paiement intégré (P7-01). Les dépôts et retraits sont reçus hors plateforme
    // et enregistrés par le Super Admin (permission wallet.adjust).
    'simulated' => true,

    // Crédit offert à l'inscription, en unité mineure (500 000 = 5 000,00 HTG). 0 (défaut) désactive le crédit.
    'welcome_credit_minor' => (int) env('WALLET_WELCOME_CREDIT_MINOR', 0),

];
