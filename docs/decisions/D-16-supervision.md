# D-16 — Supervision de la Production : suivi des erreurs, surveillance externe et canaux d'alerte

| | |
|---|---|
| **Tâche** | V2-F03 (#191) — supervision : surveillance externe, suivi des erreurs, alertes Telegram |
| **Statut** | 🟡 À décider — comparatif et recommandation proposés |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-13 |
| **Dépend de** | V2-F01 (Production, #189), D-10 (hébergeur), D-15 (prestataire SMS et WhatsApp), P9-02 (alertes Telegram et e-mail, déjà en place) |

> Les prix viennent des pages publiques et de comparatifs (novembre 2026) : **à confirmer sur la page officielle de
> chaque outil avant la décision.** Aucun compte n'est ouvert et aucun paquet n'est ajouté tant que D-16 n'est pas
> validée (nouvelle dépendance = accord préalable).

## 1. Besoin

Critère de #191 : **« Une panne provoquée déclenche une alerte en moins de 5 minutes. »**

| Panne à détecter | Exemple |
|---|---|
| Site injoignable | Serveur arrêté, Caddy en panne, DNS, certificat expiré |
| Application hors service | Base de données ou Redis arrêtés, planificateur arrêté |
| Erreurs du code | Erreurs 500 en série, ou une nouvelle erreur qui touche quelques joueurs |
| Traitements bloqués | File de tâches arrêtée, règlement en échec (déjà couvert par P9-02) |

## 2. Déjà en place (P9-02)

- `monitoring:check`, **toutes les 5 minutes** : erreurs 5xx (≥ 10 et ≥ 2 %), tâches en échec, file arrêtée,
  synchronisation en retard, tickets non réglés, écarts du grand livre, échecs MFA, changements de rôles, échecs de
  connexion répétés (F12). Envoi sur **Telegram et par e-mail**, sans répétition, avec rappel horaire des critiques et
  message de résolution.
- Centre d'alertes de l'administration (`/admin/alerts`) et page Système.
- Surveillance externe **prévue mais non branchée** (`docs/ops/monitoring-alertes.md` §2.3) : healthchecks.io
  (signal de vie, période 5 min, grâce 10 min) et UptimeRobot gratuit (`/api/v1/health` toutes les 5 min).
- Astreinte : Benginio et Rubly, groupe Telegram « REYEL 16 — Astreinte » ; runbook d'incident.
- **Aucun suivi des erreurs** : les exceptions ne sont écrites que dans les logs du serveur.

## 3. Écart avec le critère des 5 minutes

| Panne | Détection actuelle | Délai d'alerte |
|---|---|---|
| Site injoignable | UptimeRobot gratuit, toutes les 5 min | 5 à 10 min |
| Serveur ou planificateur arrêtés | healthchecks.io, 5 min + 10 min de grâce | jusqu'à 15 min |
| Erreurs 500 en série | `monitoring:check` toutes les 5 min | 5 à 10 min |
| Nouvelle erreur isolée | Aucune | jamais (sauf lecture des logs) |

## 4. Suivi des erreurs

| Outil | Prix | Points forts | Points faibles |
|---|---|---|---|
| **Sentry** | Developer gratuit (**1 utilisateur**, 5 000 erreurs/mois) ; Team ~26–29 $/mois (50 000 erreurs, utilisateurs illimités) | Référence du marché ; SDK Laravel officiel ; masquage des données personnelles ; alertes par webhook (Telegram) | Offre gratuite limitée à une personne, or l'astreinte est à deux |
| **GlitchTip** | Hébergé gratuit jusqu'à 1 000 événements/mois, 15 $ pour 100 000 ; auto-hébergement gratuit | **Compatible avec le SDK de Sentry** (changer d'outil = changer une adresse) ; équipe illimitée | Moins complet ; l'auto-hébergement demande un serveur de plus |
| **Laravel Nightwatch** | Gratuit jusqu'à 300 000 événements/mois, 20 $ pour 7,5 millions | Officiel Laravel : erreurs, requêtes lentes, files, tâches planifiées | Un processus « agent » de plus sur le serveur ; outil récent (2025) |

Dans tous les cas : données personnelles masquées avant l'envoi (téléphone, e-mail, mot de passe, en-têtes de
session) ; désactivé en développement et dans les tests ; environnement (« staging » ou « production ») et version
(`prod-sha-…`) joints à chaque erreur.

## 5. Surveillance externe

| Outil | Offre gratuite | Pour tenir les 5 minutes |
|---|---|---|
| **UptimeRobot** | 50 moniteurs, toutes les 5 min ; usage commercial permis | Offre payante, toutes les 1 min (~7–9 $/mois, à confirmer) |
| **Better Stack** | 10 moniteurs, toutes les 3 min | Telegram non confirmé dans l'offre gratuite |
| **healthchecks.io** | 20 contrôles, Telegram inclus | Grâce réduite de 10 à **2 min** : réglage gratuit |

## 6. Canaux d'alerte : SMS et WhatsApp

Deux sortes d'alertes, deux chemins :

| Alerte | Envoyée par | SMS ou WhatsApp possible |
|---|---|---|
| **Serveur arrêté** (site injoignable, serveur, base ou planificateur en panne) | Un **service externe** : notre serveur ne peut plus rien envoyer | Seulement si le service externe le propose |
| **Problème détecté par l'application** (erreurs 500, tâches en échec, écart du grand livre…) | `monitoring:check` | Par le prestataire de D-15 (WhatsApp ~0,01 $, SMS ~0,17–0,29 $ vers Haïti), code à ajouter ; inutile si le serveur est arrêté |

| Service | SMS | WhatsApp | Appel | Prix |
|---|---|---|---|---|
| **healthchecks.io** | ✅ | ✅ | Offres payantes | Gratuit : 5 SMS ou WhatsApp par mois ; Business 20 $/mois : 50 SMS ou WhatsApp et 20 appels par mois |
| **UptimeRobot** | ✅ | Non trouvé | ✅ (2 crédits) | Crédits à l'achat unique, même en gratuit : 10 pour 3 $, 100 pour 15 $ ; 1 crédit par SMS (parfois plus selon le pays, **Haïti à vérifier**) ; pas d'avertissement quand les crédits sont épuisés |

**SMS ou WhatsApp** : le SMS arrive **sans Internet**, par le seul réseau Digicel ou Natcom ; il est donc plus sûr pour
une panne grave, la nuit ou pendant une coupure. WhatsApp coûte moins cher mais demande une connexion de données ; il
convient en complément.

## 7. Recommandation

1. **Suivi des erreurs** : **Sentry Team** (~26–29 $/mois) pour l'astreinte à deux. Variante sans frais :
   **GlitchTip hébergé**, qui utilise le même SDK ; on passe de l'un à l'autre sans toucher au code.
2. **Site joignable** : **UptimeRobot** sur `/api/v1/health` **toutes les minutes** (offre payante), ou Better
   Stack (3 min) si son envoi vers Telegram est confirmé.
3. **Serveur vivant** : **healthchecks.io**, période **2 min**, grâce **2 min**.
4. **Erreurs 500** : `monitoring:check` **toutes les 2 minutes** (au lieu de 5).
5. **Ressources du serveur** (disque, mémoire, processeur) : alertes de l'hébergeur retenu en D-10 (gratuites chez
   DigitalOcean), vers l'e-mail d'astreinte.
6. **Canaux** : Telegram et e-mail pour toutes les alertes ; **SMS** pour les pannes graves (healthchecks.io et
   UptimeRobot), **WhatsApp** en complément sur healthchecks.io. Au départ : offre gratuite de healthchecks.io et
   100 crédits UptimeRobot (15 $) ; healthchecks.io Business (20 $/mois, avec appels) si les quotas deviennent justes.

Délai d'alerte attendu : **2 à 4 minutes** pour un site injoignable ou un serveur arrêté, et pour une série d'erreurs
500 ; **immédiat** pour une nouvelle erreur dans l'outil de suivi. Coût : **0 à ~60 $ par mois** selon les choix,
plus les crédits SMS.

## 8. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| SV-1 | Outil de suivi des erreurs | Sentry Team ; GlitchTip hébergé si l'on veut commencer sans frais | |
| SV-2 | Envoi des nouvelles erreurs vers Telegram | Oui, première apparition seulement, puis résumé quotidien | |
| SV-3 | Surveillance de disponibilité | UptimeRobot toutes les minutes (payant) ; Better Stack à 3 min en alternative | |
| SV-4 | Réglage de healthchecks.io | Période 2 min, grâce 2 min | |
| SV-5 | Fréquence de `monitoring:check` | Toutes les 2 minutes, charge vérifiée sur le Staging | |
| SV-6 | Alertes de ressources du serveur | Celles de l'hébergeur (D-10) : disque > 80 %, mémoire > 90 % pendant 5 min | |
| SV-7 | Environnements suivis | Staging et Production, séparés ; jamais le développement | |
| SV-8 | Conservation des erreurs | Celle de l'offre retenue (14 à 90 jours) ; données personnelles masquées | |
| SV-9 | Budget mensuel maximal | À fixer | |
| SV-10 | Canaux d'alerte | Telegram et e-mail pour tout ; SMS (et WhatsApp en complément) pour les pannes graves, par healthchecks.io et UptimeRobot | |
| SV-11 | Numéros d'astreinte | Ceux de Benginio et de Rubly, enregistrés dans les deux outils | |

## 9. Questions à confirmer

| # | Question | Réponse |
|---|---|---|
| SVJ-1 | Prix actuels : Sentry Team, UptimeRobot 1 minute, GlitchTip hébergé | |
| SVJ-2 | Better Stack gratuit : envoi vers Telegram possible ? | |
| SVJ-3 | Où l'outil retenu stocke les erreurs (États-Unis ou Europe), et pendant combien de temps (J-9) | |
| SVJ-4 | Nombre de crédits UptimeRobot par SMS vers Haïti ; réception des SMS et WhatsApp de healthchecks.io sur Digicel et Natcom | |

## 10. Conséquences techniques

- **Backend (Benginio)** : paquet du SDK retenu (`sentry/sentry-laravel` pour Sentry et GlitchTip, ou
  `laravel/nightwatch`), **nouvelle dépendance à valider** ; filtrage des données personnelles ; désactivé en
  développement et en tests ; `monitoring:check` toutes les 2 min ; variables dans les modèles `.env` du Staging et de
  la Production. Aucun changement de l'API. Envoi par WhatsApp ou SMS depuis `monitoring:check` : seulement après D-15,
  et seulement si on le décide.
- **Frontend (Rubly)** : facultatif, le SDK JavaScript pour les erreurs de l'interface (à décider à part).
- **Exploitation** : comptes des outils au nom de l'équipe, deux administrateurs ; numéros d'astreinte vérifiés ;
  rechargement des crédits UptimeRobot surveillé ; clés dans le `.env` du serveur ; mise à jour de
  `docs/ops/monitoring-alertes.md` §2.3.
- **Recette du critère de #191** : sur le Staging, puis en Production, arrêter le backend, puis la base, puis
  provoquer une erreur 500, et **mesurer le délai de chaque alerte** (< 5 min) et sa réception par SMS ; résultat
  noté dans `monitoring-alertes.md`.

## 11. Conditions pour passer à « Validée »

1. SV-1 à SV-11 tranchés par écrit.
2. Prix confirmés (SVJ-1), lieu de stockage connu (SVJ-3), réception des SMS en Haïti vérifiée (SVJ-4).
3. Accord pour la nouvelle dépendance du SDK retenu.

## 12. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |

## Sources (consultées le 13 novembre 2026)

- Sentry : [prix 2026 (Activepieces)](https://www.activepieces.com/blog/sentry-pricing-2026-developer-team-business-plans),
  [offre gratuite (Costbench)](https://costbench.com/software/developer-tools/sentry/free-plan/),
  [prix (Last9)](https://last9.io/blog/sentry-pricing/)
- Laravel Nightwatch : [prix officiels](https://nightwatch.laravel.com/pricing), [laravel.com](https://laravel.com/nightwatch/pricing)
- GlitchTip : [offre gratuite (AgentDeals)](https://agentdeals.dev/vendor/glitchtip),
  [alternatives à Sentry (OneUptime)](https://oneuptime.com/blog/post/2026-03-31-10-best-sentry-alternatives/view),
  [Better Stack vs GlitchTip](https://betterstack.com/community/comparisons/better-stack-vs-glitchtip/)
- UptimeRobot : [usage commercial](https://uptimerobot.com/knowledge-hub/monitoring/uptimerobot-for-client-work/),
  [limites de l'offre gratuite 2026](https://dev.to/r0tten0x/uptimerobot-free-plan-in-2026-the-limits-thatll-actually-bite-you-445g),
  [prix 2026 (Wakestat)](https://www.wakestat.com/uptimerobot-pricing), [prix officiels](https://uptimerobot.com/pricing.md),
  [crédits SMS et appels](https://help.uptimerobot.com/en/articles/11361302-sms-and-voice-call-credits),
  [acheter des crédits](https://help.uptimerobot.com/en/articles/11358321-buy-extra-sms-or-voice-alerts)
- Better Stack et outils gratuits : [Notifier](https://notifier.so/guides/best-free-website-monitoring-tools/),
  [Uptrack](https://uptrack.app/blog/best-free-uptime-monitoring-tools),
  [Notifier — alertes SMS et appels](https://notifier.so/guides/best-uptime-monitoring-with-sms-alerts/)
- healthchecks.io : [prix officiels](https://healthchecks.io/pricing), [site](https://healthchecks.io),
  [Freetier](https://freetier.co/directory/products/healthchecksio)
