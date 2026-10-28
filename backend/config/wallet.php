<?php

/*
| Module Wallet & Ledger.
| Devise, arrondis et limites : décision D-06 (tâche P0-05) — valeurs provisoires.
*/

return [

    // Devise unique de la V1 (ISO 4217).
    'currency' => env('APP_CURRENCY', 'HTG'),

    // V1 : fonds fictifs uniquement. Aucun mouvement réel tant que P7-01 n'est pas débloqué.
    'simulated' => true,

];
