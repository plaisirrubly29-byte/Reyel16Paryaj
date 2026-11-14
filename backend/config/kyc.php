<?php

/*
| KYC — vérification d'identité (V2-C03). Le joueur envoie une pièce d'identité et un selfie ; un administrateur
| habilité valide (kyc.approve) ou refuse avec motif (kyc.reject). Décision D-09 : KYC avant l'argent réel.
*/

return [

    // Disque privé (config/filesystems.php) : fichiers chiffrés par l'application (APP_KEY), jamais servis en direct.
    'disk' => env('KYC_DISK', 'kyc'),

    // Dépôts libres jusqu'à ce cumul (10 000,00 HTG) ; au-delà, KYC validé exigé. Tout retrait exige un KYC validé.
    'deposit_threshold_minor' => (int) env('KYC_DEPOSIT_THRESHOLD_MINOR', 10_000_00),

    // Fichiers envoyés : photos (ou PDF pour la pièce), 5 Mo au plus chacun.
    'max_file_kilobytes' => 5_120,
    'document_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    'selfie_mimes' => ['jpg', 'jpeg', 'png', 'webp'],

];
