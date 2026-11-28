<?php

/*
| Réclamations (V2-G04). Un joueur ouvre une réclamation numérotée (REC-AAAA-NNNNNN), liée ou non à un de ses tickets
| ou à une écriture de son portefeuille, avec des pièces jointes ; l'équipe la suit par statut. Contrat :
| docs/api/openapi.yaml (tag Complaints).
*/

return [

    // Disque privé (config/filesystems.php) : pièces chiffrées par l'application (APP_KEY), jamais servies en direct.
    'disk' => env('COMPLAINTS_DISK', 'complaints'),

    // Pièces jointes : 5 au plus par envoi, 5 Mo chacune ; type vérifié sur le contenu du fichier, pas sur son nom.
    'max_attachments' => 5,
    'max_file_kilobytes' => 5_120,
    'attachment_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    'attachment_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],

    // Limites par joueur : réclamations ouvertes et messages envoyés par heure (429 au-delà).
    'max_per_hour' => (int) env('COMPLAINTS_MAX_PER_HOUR', 5),
    'max_messages_per_hour' => (int) env('COMPLAINTS_MAX_MESSAGES_PER_HOUR', 20),

    // Réouverture par le joueur possible jusqu'à ce délai après le passage en resolved.
    'reopen_window_days' => (int) env('COMPLAINTS_REOPEN_WINDOW_DAYS', 30),

    // Une réclamation resolved passe seule en closed après ce délai sans réouverture.
    'auto_close_days' => (int) env('COMPLAINTS_AUTO_CLOSE_DAYS', 7),

];
