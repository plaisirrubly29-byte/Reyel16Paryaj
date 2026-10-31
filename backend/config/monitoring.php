<?php

/*
|--------------------------------------------------------------------------
| Monitoring et alertes (P9-02) — voir docs/ops/monitoring-alertes.md
|--------------------------------------------------------------------------
|
| `php artisan monitoring:check` (planifiée toutes les 5 minutes) évalue les alertes et les envoie sur Telegram et
| par e-mail : une fois à l'apparition, rappel des alertes critiques, puis une fois à la résolution.
| Sans canal configuré, les alertes ne sont qu'écrites dans les logs.
|
*/

return [

    // Nom de l'environnement dans les messages (ex. « Staging », « Production »).
    'label' => env('MONITORING_LABEL', env('APP_ENV', 'production')),

    'telegram' => [
        // Bot créé avec @BotFather ; chat_id du groupe d'astreinte (le bot doit en être membre).
        'bot_token' => env('MONITORING_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('MONITORING_TELEGRAM_CHAT_ID'),
    ],

    // Adresses de l'astreinte, séparées par des virgules (envoi par le mailer de l'application).
    'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_MAIL_TO', ''))))),

    // Signal de vie externe (ex. healthchecks.io) appelé à chaque contrôle : si le serveur ou le planificateur
    // s'arrête, c'est ce service qui alerte (l'application ne peut pas signaler sa propre panne).
    'heartbeat_url' => env('MONITORING_HEARTBEAT_URL'),

    // Erreurs API : au moins `min_errors` réponses 5xx ET au moins `max_rate` des réponses, sur `window_minutes`.
    'api_errors' => [
        'window_minutes' => (int) env('MONITORING_API_ERRORS_WINDOW', 5),
        'min_errors' => (int) env('MONITORING_API_ERRORS_MIN', 10),
        'max_rate' => (float) env('MONITORING_API_ERRORS_RATE', 0.02),
    ],

    // Retard de synchronisation : matchs à venir dont la dernière mise à jour (cotes) est plus ancienne.
    'sync_delay_hours' => (int) env('MONITORING_SYNC_DELAY_HOURS', 6),

    // Une alerte critique toujours active est renvoyée à cet intervalle (minutes).
    'critical_reminder_minutes' => (int) env('MONITORING_CRITICAL_REMINDER', 60),

];
