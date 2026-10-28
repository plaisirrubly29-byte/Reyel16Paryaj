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
        'timeout' => (int) env('SPORTS_DATA_TIMEOUT', 30),

        // Coût d'un appel de cotes = nombre de marchés × nombre de régions (en crédits).
        // Marchés pris en charge : h2h (1X2) et totals (plus/moins, "Nombre de buts").
        // "h2h,totals" avec la région "eu" = 2 crédits par compétition et par synchro.
        'regions' => env('SPORTS_DATA_REGIONS', 'eu'),
        'markets' => env('SPORTS_DATA_MARKETS', 'h2h'),

        // Bookmaker de référence pour les cotes publiées (null = premier disponible). À valider (P0-04).
        'reference_bookmaker' => env('SPORTS_DATA_REFERENCE_BOOKMAKER'),
    ],

    // Sports synchronisés (clés du fournisseur), ex. "soccer_epl,basketball_nba". Liste V1 : décision D-03 (P0-03).
    'sports' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPORTS_DATA_SPORTS', 'soccer_epl'))))),

    // Noms affichés des compétitions (le fournisseur n'a que des codes : "EPL", "NBA"…).
    'competition_labels' => [
        'soccer_epl' => ['name' => 'Premier League', 'country' => 'Angleterre'],
        'soccer_france_ligue_one' => ['name' => 'Ligue 1', 'country' => 'France'],
        'soccer_spain_la_liga' => ['name' => 'LaLiga', 'country' => 'Espagne'],
        'soccer_italy_serie_a' => ['name' => 'Serie A', 'country' => 'Italie'],
        'soccer_germany_bundesliga' => ['name' => 'Bundesliga', 'country' => 'Allemagne'],
        'soccer_uefa_champs_league' => ['name' => 'Ligue des champions', 'country' => 'Europe'],
        'soccer_uefa_europa_league' => ['name' => 'Ligue Europa', 'country' => 'Europe'],
        'soccer_uefa_europa_conference_league' => ['name' => 'Ligue Europa Conférence', 'country' => 'Europe'],
        'soccer_uefa_nations_league' => ['name' => 'Ligue des nations', 'country' => 'Europe'],
        'soccer_conmebol_copa_libertadores' => ['name' => 'Copa Libertadores', 'country' => 'Amérique du Sud'],
        'soccer_conmebol_copa_sudamericana' => ['name' => 'Copa Sudamericana', 'country' => 'Amérique du Sud'],
        'soccer_efl_champ' => ['name' => 'Championship', 'country' => 'Angleterre'],
        'soccer_france_ligue_two' => ['name' => 'Ligue 2', 'country' => 'France'],
        'soccer_brazil_campeonato' => ['name' => 'Brasileirão Série A', 'country' => 'Brésil'],
        'soccer_usa_mls' => ['name' => 'MLS', 'country' => 'États-Unis'],
        'soccer_saudi_arabia_pro_league' => ['name' => 'Saudi Pro League', 'country' => 'Arabie saoudite'],
        'soccer_mexico_ligamx' => ['name' => 'Liga MX', 'country' => 'Mexique'],
        'basketball_nba' => ['name' => 'NBA', 'country' => 'États-Unis'],
        'basketball_euroleague' => ['name' => 'Euroligue', 'country' => 'Europe'],
        'baseball_mlb' => ['name' => 'MLB', 'country' => 'États-Unis'],
        'americanfootball_nfl' => ['name' => 'NFL', 'country' => 'États-Unis'],
        'icehockey_nhl' => ['name' => 'NHL', 'country' => 'États-Unis'],
    ],

    // « Top compétitions » : affichées en premier, dans cet ordre (les autres suivent par ordre alphabétique).
    'featured_competitions' => [
        'soccer_uefa_champs_league', 'soccer_uefa_europa_league', 'soccer_uefa_europa_conference_league',
        'soccer_epl', 'soccer_spain_la_liga', 'soccer_italy_serie_a', 'soccer_germany_bundesliga', 'soccer_france_ligue_one',
        'soccer_conmebol_copa_libertadores', 'soccer_conmebol_copa_sudamericana', 'soccer_uefa_nations_league',
        'soccer_brazil_campeonato', 'soccer_usa_mls', 'soccer_saudi_arabia_pro_league', 'soccer_efl_champ',
        'basketball_nba', 'basketball_euroleague', 'americanfootball_nfl', 'icehockey_nhl', 'baseball_mlb',
    ],

    // Garde-fou : plus aucun appel payant quand le quota restant passe sous ce seuil.
    // C'est aussi la réserve que le budget de crédits ne dépense jamais.
    'min_remaining_requests' => (int) env('SPORTS_DATA_MIN_REMAINING', 25),

    // Budget de crédits (Services/CreditBudget) : les crédits restants sont répartis sur les synchros
    // prévues d'ici la remise à zéro du quota ; les compétitions en trop sont reportées à la synchro suivante.
    'budget_enabled' => (bool) env('SPORTS_DATA_BUDGET', true),
    // Crédits de l'offre par mois (offre gratuite : 500) et jour du mois où le quota repart à zéro.
    'monthly_credits' => (int) env('SPORTS_DATA_MONTHLY_CREDITS', 500),
    'quota_reset_day' => (int) env('SPORTS_DATA_QUOTA_RESET_DAY', 1),

    // Synchronisation (P3-03). Coût mensuel ≈ nb de compétitions × nb d'exécutions par mois × marchés × régions.
    // Par défaut toutes les 4 h : 1 compétition ≈ 180 crédits/mois en h2h, ≈ 360 en h2h,totals (offre gratuite : 500).
    'sync_cron' => env('SPORTS_DATA_SYNC_CRON', '0 */4 * * *'),
    'horizon_days' => (int) env('SPORTS_DATA_HORIZON_DAYS', 7),
    'max_events_per_competition' => (int) env('SPORTS_DATA_MAX_EVENTS', 10),

    // Règle de fraîcheur (P3-04) : au-delà, la cote n'est plus proposée. Doit dépasser l'intervalle de synchro.
    'max_odds_age_minutes' => (int) env('SPORTS_DATA_MAX_ODDS_AGE', 300),

    // Import des résultats (P5-04) : seules les compétitions avec un match commencé depuis results_delay_minutes
    // et sans résultat sont interrogées (2 crédits chacune chez The Odds API). Par défaut toutes les heures.
    'results_cron' => env('SPORTS_DATA_RESULTS_CRON', '20 * * * *'),
    // Durée minimale entre le début du match et la première demande de résultat (football : ~1 h 45).
    'results_delay_minutes' => (int) env('SPORTS_DATA_RESULTS_DELAY', 105),

    // Matchs en direct (sports-data:live) : score et cotes des matchs commencés depuis moins de live_window_minutes,
    // seulement pour les compétitions qui en ont un (aucun appel sinon). Coût par compétition et par passage :
    // 1 crédit (scores) + marchés × régions (cotes). live_daily_credits plafonne la dépense du jour.
    'live_enabled' => (bool) env('SPORTS_DATA_LIVE', true),
    'live_cron' => env('SPORTS_DATA_LIVE_CRON', '*/15 * * * *'),
    'live_window_minutes' => (int) env('SPORTS_DATA_LIVE_WINDOW', 180),
    'live_daily_credits' => (int) env('SPORTS_DATA_LIVE_DAILY_CREDITS', 30),
    // Règle de fraîcheur en direct : une cote bouge vite pendant le match, elle est bloquée plus tôt.
    'max_live_odds_age_minutes' => (int) env('SPORTS_DATA_MAX_LIVE_ODDS_AGE', 20),

];
