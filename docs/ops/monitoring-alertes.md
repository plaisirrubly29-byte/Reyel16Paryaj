# Monitoring et alertes de production — P9-02

| | |
|---|---|
| **Tâche** | P9-02 — Monitoring et alertes de production (ticket #57) |
| **Code** | Module [`backend/app/Modules/Monitoring`](../../backend/app/Modules/Monitoring/README.md), commande `monitoring:check` (toutes les 5 min) |
| **Réglages** | [`backend/config/monitoring.php`](../../backend/config/monitoring.php), variables `MONITORING_*` du `.env` |
| **Incident** | [Runbook d'incident](runbook-incident.md) |

L'écran de supervision de l'administration (Vue d'ensemble, Système) **montre** les points d'attention ; ce
dispositif **prévient** l'astreinte, même quand personne ne regarde l'écran.

## 1. Alertes

| Alerte | Gravité | Déclenchement (réglable) | Première action (runbook) |
|---|---|---|---|
| **Erreurs de l'API** | 🔴 critique | ≥ 10 réponses 5xx **et** ≥ 2 % des réponses sur 5 min | « API indisponible ou erreurs 500 » : logs filtrés par `request_id` |
| **Retard de synchronisation du catalogue** | 🟠 avertissement | Matchs à venir non mis à jour depuis plus de 6 h | « Fournisseur de cotes en panne ou quota épuisé » ; planificateur |
| **Aucune synchronisation depuis 24 h** / **crédits du fournisseur** | 🟠 / 🔴 | Vrai fournisseur seulement (AlertService) | Idem |
| **Tickets en attente de règlement** | 🟠 avertissement | Ticket en cours 6 h après le début d'un de ses matchs | « Règlement bloqué ou en erreur » |
| **Tâches automatiques en échec** | 🔴 critique | Au moins une tâche en file en échec sur 24 h (règlement…) | « Règlement bloqué ou en erreur » ; `/admin/system` |
| **Travailleur de file arrêté** | 🔴 critique | Pas de signal de `queue:work` depuis 5 min | Relancer le conteneur `queue` ; règlement ensuite rattrapé par `settlement:run` |
| **Écarts de rapprochement** | 🔴 critique | Au moins un écart dans le grand livre | « Anomalie financière » : **maintenance d'abord**, ne rien corriger à la main |
| **Correction financière à valider** | 🟠 avertissement | Demande en attente d'un second administrateur | Écran Finance |
| **Échecs de double authentification** / **changements de rôles** | 🟠 avertissement | ≥ 5 échecs MFA en 24 h ; rôle donné ou retiré en 72 h | « Incident de sécurité » si non prévu |

Les alertes « info » de l'administration (ex. marchés suspendus) ne sont pas envoyées.

**Sans répétition.** Une alerte est envoyée à son apparition, de nouveau si elle passe d'avertissement à critique,
rappelée toutes les heures tant qu'elle reste critique, et une fois « ✅ Résolu » quand elle disparaît.

**Panne du serveur lui-même.** Une application arrêtée ne peut pas signaler sa propre panne : deux services externes
gratuits s'en chargent (§2.3).

## 2. Mise en service (par environnement)

### 2.1 Telegram

1. Créer un bot avec [@BotFather](https://t.me/BotFather) (`/newbot`) : il donne le **jeton** du bot.
2. Créer un groupe « REYEL 16 — Astreinte » avec Benginio et Rubly, y ajouter le bot.
3. Envoyer un message dans le groupe, puis lire `https://api.telegram.org/bot<jeton>/getUpdates` : `chat.id`
   (négatif pour un groupe) est l'identifiant du groupe.
4. Dans le `.env` du serveur : `MONITORING_TELEGRAM_BOT_TOKEN=<jeton>`, `MONITORING_TELEGRAM_CHAT_ID=<id>`.
   Le jeton est un secret : jamais dans Git ni dans un message.

### 2.2 E-mail

`MONITORING_MAIL_TO=adresse1,adresse2` et un mailer réel (`MAIL_MAILER=smtp`, `MAIL_HOST`…) : avec `MAIL_MAILER=log`,
les e-mails ne partent pas (ils sont écrits dans les logs).

### 2.3 Surveillance externe (le serveur hors service)

| Service | Réglage | Alerte quand |
|---|---|---|
| [healthchecks.io](https://healthchecks.io) (gratuit) | Créer un contrôle « période 5 min, grâce 10 min » ; son URL de ping dans `MONITORING_HEARTBEAT_URL` | `monitoring:check` ne tourne plus : serveur, planificateur ou base arrêtés |
| [UptimeRobot](https://uptimerobot.com) (gratuit) | Moniteur HTTPS sur `https://<domaine>/api/v1/health`, toutes les 5 min | Site injoignable, certificat expiré |

Les deux envoient sur le même groupe Telegram et par e-mail (intégrations natives).

### 2.4 Tester (critère « Alertes testées »)

```sh
php artisan monitoring:check --test   # message de test sur chaque canal : « envoyé » / « ÉCHEC » / « non configuré »
php artisan monitoring:check          # contrôle réel, liste des alertes actives
```

À refaire à chaque mise en service et après tout changement de jeton, de groupe ou de mailer. Puis vérifier les
alertes externes : bouton « Send test notification » de healthchecks.io et d'UptimeRobot.

## 3. Astreinte

| | |
|---|---|
| **Qui** | **Benginio Jean-Baptiste et Rubly PLAISIR, tous les deux en permanence** |
| **Quoi** | Toutes les alertes (critiques et avertissements), sur Telegram et par e-mail, à toute heure |
| **Prise en charge** | Le **premier disponible** répond « je prends » dans le groupe Telegram : il devient responsable d'incident (runbook §2) et tient l'autre informé |
| **Délais** | Critique : prise en charge immédiate (P1/P2 du runbook). Avertissement : dans la journée |
| **Sans réponse** | Après 15 min sans « je prends » sur une alerte critique : appel téléphonique de l'un à l'autre |
| **Absence** | Celui qui part (congés, sans réseau) le dit dans le groupe : l'autre est seul d'astreinte jusqu'à son retour |
| **Révision** | À chaque arrivée dans l'équipe, et au lancement (P9-01) |

## 4. Exploitation

- Seuils : variables `MONITORING_API_ERRORS_*`, `MONITORING_SYNC_DELAY_HOURS`, `MONITORING_CRITICAL_REMINDER`.
- Historique : chaque alerte et chaque résolution est écrite dans les logs (`monitoring.alert`,
  `monitoring.alert_resolved`) ; un canal en échec aussi (`monitoring.telegram_failed`, `monitoring.mail_failed`).
- Faux positif répété : ajuster le seuil plutôt que d'ignorer l'alerte, et le noter dans le post-mortem.
