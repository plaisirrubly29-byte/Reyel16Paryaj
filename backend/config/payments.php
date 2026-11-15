<?php

use App\Modules\Payments\Providers\FakePaymentProvider;
use App\Modules\Payments\Providers\PlopplopPaymentProvider;

/*
| Module Payments Adapter (V2-B01, V2-B02).
| Prestataire retenu : Plop Plop (MonCash, NatCash, Kashpaw, carte). Un prestataire n'est utilisable (paiement, webhook)
| que s'il est listé ici ET activé. L'ordre de la liste est celui proposé au joueur.
*/

return [

    'providers' => [
        // Plop Plop (MonCash, NatCash, Kashpaw, carte). À configurer dans l'espace marchand :
        // webhook_url = <site>/api/v1/payments/webhooks/plopplop, lien_retour = <frontend>/wallet.
        'plopplop' => [
            'class' => PlopplopPaymentProvider::class,
            'label' => 'Plop Plop', // libellé d'un ancien dépôt ouvert sans moyen choisi
            // Moyens choisis par le joueur sur notre formulaire (code payment_method de Plop Plop => libellé) : le
            // joueur arrive directement sur la page de l'opérateur, sans passer par la page de choix de Plop Plop.
            'methods' => ['moncash' => 'MonCash', 'natcash' => 'NatCash'],
            'enabled' => (bool) env('PAYMENTS_PLOPPLOP_ENABLED', false),
            'base_url' => env('PAYMENTS_PLOPPLOP_BASE_URL', 'https://plopplop.solutionip.app/'),
            'client_id' => env('PAYMENTS_PLOPPLOP_CLIENT_ID'),
            // Clé du webhook (X-Webhook-Signature) et, plus tard, des retraits signés. Jamais exposée au navigateur.
            'client_secret' => env('PAYMENTS_PLOPPLOP_CLIENT_SECRET'),
            'timeout' => (int) env('PAYMENTS_PLOPPLOP_TIMEOUT', 15),
        ],

        // Faux prestataire des tests et du développement : signature HMAC comme un vrai. Interdit en production
        // (App\Support\ProductionSafety).
        'fake' => [
            'class' => FakePaymentProvider::class,
            'label' => 'Paiement test', // libellé de l'écriture vue par le joueur
            // Mêmes moyens que Plop Plop, pour essayer le vrai parcours (choix, page simulée de l'opérateur).
            'methods' => ['moncash' => 'MonCash (test)', 'natcash' => 'NatCash (test)'],
            'enabled' => (bool) env('PAYMENTS_FAKE_ENABLED', false),
            'secret' => env('PAYMENTS_FAKE_SECRET'),
        ],
    ],

    // Un webhook signé plus ancien que ce délai est refusé (rejeu d'une requête capturée).
    'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),

    // Un dépôt jamais confirmé passe en « expired » après ce délai (commande payments:expire).
    // Un succès reçu plus tard est quand même crédité : l'argent a quitté le compte du joueur.
    'deposit_expiry_minutes' => (int) env('PAYMENTS_DEPOSIT_EXPIRY_MINUTES', 30),

    // Montants d'un dépôt ouvert par le joueur, en unité mineure (100,00 HTG à 100 000,00 HTG par défaut).
    'deposit_min_minor' => (int) env('PAYMENTS_DEPOSIT_MIN_MINOR', 100_00),
    'deposit_max_minor' => (int) env('PAYMENTS_DEPOSIT_MAX_MINOR', 100_000_00),

    /*
    | Retraits des joueurs (V2-B03) : le montant est réservé au ledger à la demande, puis versé automatiquement par le
    | prestataire (commande payments:payouts, chaque minute) depuis son portefeuille marchand, sans validation manuelle
    | (choix de Rubly du 2026-11-13). Désactivés par défaut.
    */
    'withdrawals' => [
        'enabled' => (bool) env('PAYMENTS_WITHDRAWALS_ENABLED', false),
        // Identité validée par la revue KYC (V2-C03) exigée avant tout retrait (contrat V2-B03). À ne couper qu'en
        // développement.
        'require_kyc' => (bool) env('PAYMENTS_WITHDRAWALS_REQUIRE_KYC', true),
        // Prestataire qui verse (doit être activé ci-dessus) : plopplop, ou fake en développement.
        'provider' => env('PAYMENTS_WITHDRAWALS_PROVIDER', 'plopplop'),
        // Portefeuilles mobiles acceptés (libellé vu par le joueur).
        'methods' => ['moncash' => 'MonCash', 'natcash' => 'NatCash'],
        // Bornes d'un retrait et plafond par joueur sur 24 heures glissantes, en unité mineure.
        'min_minor' => (int) env('PAYMENTS_WITHDRAWAL_MIN_MINOR', 100_00),
        'max_minor' => (int) env('PAYMENTS_WITHDRAWAL_MAX_MINOR', 50_000_00),
        'daily_max_minor' => (int) env('PAYMENTS_WITHDRAWAL_DAILY_MAX_MINOR', 100_000_00),
    ],

];
