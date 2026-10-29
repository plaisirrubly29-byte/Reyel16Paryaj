<?php

/*
| Reverse proxy devant l'API (Caddy / Traefik, proposition d'hébergement D-10) — revue P8-02, B2.
| Sans proxy déclaré, Laravel voit toutes les requêtes venir de l'IP du proxy, en HTTP : les limiteurs
| par IP deviennent communs à tout le site, l'audit enregistre l'IP du proxy et isSecure() reste faux
| (pas de cookie Secure automatique ni de HSTS).
|
| TRUSTED_PROXIES : IP ou plages CIDR du proxy, séparées par des virgules (ex. "10.0.0.2,172.18.0.0/16").
| Jamais "*" en production : n'importe quel client pourrait alors falsifier X-Forwarded-For.
| Vide (par défaut) : aucun proxy de confiance, les en-têtes X-Forwarded-* sont ignorés.
*/

return [

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
