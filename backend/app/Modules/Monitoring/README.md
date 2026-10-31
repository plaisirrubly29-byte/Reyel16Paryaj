# Module Monitoring — P9-02

Alertes de production poussées à l'astreinte (Telegram, e-mail). Procédure, seuils et astreinte :
[`docs/ops/monitoring-alertes.md`](../../../../docs/ops/monitoring-alertes.md).

## Responsabilités

- Compter les réponses de l'API et les erreurs 5xx (`Http/Middleware/CountApiResponses`, groupe `api`).
- Évaluer les alertes (`Services/MonitoringService`) : erreurs API, retard de synchronisation, travailleur de file
  arrêté, plus les points d'attention de l'administration (`Admin\Services\AlertService`, lus sans être modifiés).
- Envoyer sans répétition (`Services/AlertDispatcher`, état dans le cache) sur chaque canal configuré
  (`Services/AlertNotifier`), puis appeler le signal de vie externe.

## Utilisation

```sh
php artisan monitoring:check          # planifiée toutes les 5 minutes (routes/console.php)
php artisan monitoring:check --test   # message de test sur chaque canal
```

## Règles à respecter

- Lecture seule : le monitoring ne corrige rien, il prévient.
- Aucun secret dans les logs ni dans les messages (jeton du bot Telegram).
- Un canal en panne ne bloque ni l'autre canal, ni la requête de l'utilisateur (compteurs protégés).

Tests : `tests/Feature/Modules/Monitoring`.
