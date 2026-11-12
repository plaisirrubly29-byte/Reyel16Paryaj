<?php

use App\Modules\Payments\Providers\FakePaymentProvider;

/*
| Module Payments Adapter (V2-B01).
| Aucun prestataire réel tant que D-08 n'est pas validée : MonCash (et NatCash si retenu) arriveront avec V2-B02.
| Un prestataire n'est utilisable (paiement, webhook) que s'il est listé ici ET activé.
*/

return [

    'providers' => [
        // Faux prestataire des tests et du développement : signature HMAC comme un vrai. Interdit en production
        // (App\Support\ProductionSafety).
        'fake' => [
            'class' => FakePaymentProvider::class,
            'label' => 'Paiement test', // libellé de l'écriture vue par le joueur
            'enabled' => (bool) env('PAYMENTS_FAKE_ENABLED', false),
            'secret' => env('PAYMENTS_FAKE_SECRET'),
        ],
    ],

    // Un webhook signé plus ancien que ce délai est refusé (rejeu d'une requête capturée).
    'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),

    // Un dépôt jamais confirmé passe en « expired » après ce délai (commande payments:expire).
    // Un succès reçu plus tard est quand même crédité : l'argent a quitté le compte du joueur.
    'deposit_expiry_minutes' => (int) env('PAYMENTS_DEPOSIT_EXPIRY_MINUTES', 30),

];
