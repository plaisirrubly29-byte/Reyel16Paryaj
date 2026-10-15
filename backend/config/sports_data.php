<?php

/*
| Module Sports Data — fournisseur de données sportives et de cotes (décision D-02, tâche P0-02).
| Les secrets restent dans .env, jamais dans le code ni le frontend.
*/

return [

    // "fake" (développement, tests) ou "theodds" (The Odds API).
    'provider' => env('SPORTS_DATA_PROVIDER', 'fake'),

    'theodds' => [
        'url' => env('SPORTS_DATA_API_URL', 'https://api.the-odds-api.com/v4'),
        'key' => env('SPORTS_DATA_API_KEY'),
        'timeout' => (int) env('SPORTS_DATA_TIMEOUT', 10),

        // Coût d'un appel de cotes = nombre de marchés × nombre de régions (en crédits).
        'regions' => env('SPORTS_DATA_REGIONS', 'eu'),
        'markets' => env('SPORTS_DATA_MARKETS', 'h2h'),

        // Bookmaker de référence pour les cotes publiées (null = premier disponible). À valider (P0-04).
        'reference_bookmaker' => env('SPORTS_DATA_REFERENCE_BOOKMAKER'),
    ],

    // Sports synchronisés (clés du fournisseur), ex. "soccer_epl,basketball_nba". Liste V1 : décision D-03 (P0-03).
    'sports' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPORTS_DATA_SPORTS', 'soccer_epl'))))),

    // Garde-fou : plus aucun appel payant quand le quota restant passe sous ce seuil.
    'min_remaining_requests' => (int) env('SPORTS_DATA_MIN_REMAINING', 25),

];
