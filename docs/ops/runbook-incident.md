# Runbook d'incident — P8-04

| | |
|---|---|
| **Tâche** | P8-04 — procédure d'incident (ticket #55) |
| **Portée** | Staging et production. En local, données et fonds fictifs : suivre la même démarche pour s'entraîner. |
| **Liés** | [Sauvegarde et restauration](sauvegarde-restauration.md) · D-10 (RPO 15 min, RTO 4 h) · P9-02 (alertes et astreinte, à venir) |

Règle d'or : **ne jamais modifier le ledger, les tickets, les résultats ni le journal d'audit à la main.** Ils sont
en ajout seul (déclencheurs PostgreSQL) ; toute correction passe par une écriture compensatoire auditée (P6-04).

## 1. Gravité

| Niveau | Exemples | Délai de prise en charge |
|---|---|---|
| **P1 — critique** | Argent ou ledger incohérent, perte ou corruption de données, fuite de données ou de secrets, paris acceptés à tort | Immédiat |
| **P2 — majeur** | Paris impossibles pour tous, connexion impossible, règlement bloqué | Moins d'1 heure |
| **P3 — mineur** | Fonction dégradée (un sport sans cotes, lenteur), contournement possible | Jour ouvré |

En cas de doute, classer au niveau supérieur.

## 2. Rôles

Dans une équipe de deux, une même personne peut tenir plusieurs rôles, mais **le responsable d'incident est nommé
explicitement** au début.

| Rôle | Mission |
|---|---|
| **Responsable d'incident** | Décide (maintenance, restauration, retour arrière), coordonne, déclare la fin |
| **Opérateur** | Exécute les commandes, une à la fois, annoncées avant d'être lancées |
| **Communication** | Informe les joueurs (bannière, réseaux) et l'équipe ; prévient le conseil juridique si P1 de données (J-9) |
| **Secrétaire** | Tient le journal horodaté de l'incident (actions, décisions, commandes) — base du post-mortem |

Astreinte et canal d'alerte : à définir avec P9-02. En attendant : Benginio Jean-Baptiste et Rubly PLAISIR.

## 3. Déroulé général

1. **Détecter et déclarer** : ouvrir le journal d'incident (heure, symptôme, niveau), nommer le responsable.
2. **Évaluer** : périmètre (qui, quoi, depuis quand), argent en jeu ? données exposées ?
3. **Contenir** : si l'argent ou les données sont en jeu, **maintenance d'abord** :
   `php artisan down --secret=<jeton>` (l'équipe garde l'accès par l'URL secrète), puis arrêter worker et planificateur.
4. **Diagnostiquer** : logs JSON (`storage/logs/laravel.json.log`) filtrés par `request_id` (en-tête `X-Request-Id`
   renvoyé à chaque réponse), journal d'audit (`GET /admin/audit-logs`), derniers déploiements.
5. **Corriger** : la plus petite action sûre (retour à la version précédente, restauration, désactivation d'une fonction).
6. **Vérifier** : `/api/v1/health`, connexion, consultation d'un ticket, solde, un pari de test (staging).
7. **Rétablir** : `php artisan up`, worker et planificateur relancés, communication de fin.
8. **Post-mortem** sous 5 jours ouvrés (§5).

## 4. Scénarios

### API indisponible ou erreurs 500 (P2)
1. `/api/v1/health` et état des conteneurs (`docker compose ps`) ou des services.
2. Logs : la dernière erreur et son `request_id`.
3. Déploiement récent ? **Revenir à la version précédente** avant de chercher plus loin.
4. Base ou Redis injoignable : voir les scénarios suivants. Rappel local : l'API doit tourner avec
   `artisan serve --no-reload`, sinon elle ignore `DB_HOST` et `REDIS_HOST` du conteneur (#90).

### Base perdue ou corrompue (P1)
1. Maintenance (§3.3). **Ne rien supprimer** : l'ancienne base est une pièce de l'enquête.
2. Point de restauration : PITR juste avant l'incident (base managée), sinon dernier dump valide.
3. Restaurer **dans une nouvelle base** puis vérifier :
   `scripts/db/restore.sh <dump> <nouvelle_base>` puis `scripts/db/verify.sh <manifeste> <nouvelle_base>`
   (détail : [sauvegarde-restauration.md §4](sauvegarde-restauration.md#4-procédure-de-restauration-production)).
4. Basculer l'application vers la base restaurée, vérifier (§3.6).
5. **Période perdue** (entre le point de restauration et l'incident) : lister les paris et écritures du ledger
   connus ailleurs (logs, reçus des joueurs), les rétablir par correction auditée. Prévenir les joueurs concernés.

### Fournisseur de cotes en panne ou quota épuisé (P3, P2 si tous les marchés)
1. `php artisan sports-data:check` et `php artisan sports-data:credits`.
2. Comportement attendu, **sans intervention** : la synchronisation n'écrit rien, les cotes vieillissent et les paris
   sont refusés (`selection_unavailable`) — vérifié par les tests d'acceptation P4-07.
3. **Ne jamais** rouvrir un marché ni prolonger la fraîcheur des cotes pour « faire passer » des paris.
4. Communiquer (« paris momentanément indisponibles ») ; contacter le fournisseur.

### Règlement bloqué ou en erreur (P2)
1. `php artisan settlement:run` : idempotent, ne paie jamais deux fois (P5-05, P4-07 critère 5).
2. Un marché inconnu reste en attente et apparaît dans le rapport : le régler par le processus prévu, pas à la main.
3. Résultat erroné du fournisseur : une correction crée une nouvelle version tracée ; un ticket déjà payé n'est
   pas repayé. Les gains versés à tort se corrigent par écriture compensatoire auditée à double validation (P6-04).

### Anomalie financière : solde incohérent, paiement en double (P1)
1. Maintenance si l'anomalie peut s'étendre.
2. Contrôle en lecture seule de la cohérence du ledger (même requête que `verify.sh`, contrôle 3) sur la base en service.
3. Geler les portefeuilles concernés (statut du portefeuille), **sans modifier le ledger**.
4. Corriger par écritures compensatoires auditées (P6-04, double validation), puis revérifier.

### Paris acceptés à tort (P1)
Exemples : match commencé, cote périmée, paris en direct hors périmètre (E1 de la revue P8-02).
1. Suspendre les marchés concernés : `PATCH /api/v1/admin/markets/{id}` avec motif (P6-02).
2. Lister les tickets concernés ; décision (annulation et remboursement) par le responsable d'incident, appliquée
   par le processus de correction audité.

### Incident de sécurité : secret exposé, accès suspect (P1)
1. **Révoquer d'abord** : clé The Odds API, mot de passe de la base, jetons d'accès exposés.
2. `APP_KEY` compromise : générer une nouvelle clé **en gardant l'ancienne dans `APP_PREVIOUS_KEYS`**, sinon les secrets
   MFA chiffrés en base deviennent illisibles. Toutes les sessions sont alors invalidées.
3. Compte administrateur compromis : le suspendre (`PATCH /admin/users/{id}`), réinitialiser son MFA, revoir le journal
   d'audit de ses actions.
4. Données personnelles exposées : prévenir le conseil juridique (obligations de notification, J-9).

## 5. Post-mortem (modèle)

Sans recherche de coupable : on corrige le système, pas les personnes.

```text
Incident : <titre>                    Niveau : P1 | P2 | P3
Début / détection / fin : <heures UTC>  Durée d'impact : <min>
Responsable d'incident : <nom>
Impact : <joueurs, paris, montants, données>
Chronologie : <heure — fait — décision — commande>
Cause racine : <cause technique et pourquoi elle n'a pas été détectée plus tôt>
Ce qui a bien fonctionné / moins bien fonctionné :
Actions (une personne et une date par action) :
  - [ ] <correction>        — <qui> — <quand>
  - [ ] <détection/alerte>  — <qui> — <quand>
  - [ ] <procédure/runbook> — <qui> — <quand>
```

Le runbook se met à jour après chaque incident et chaque test de restauration.

Avant une mise en production et pour revenir en arrière après un déploiement : [lancement.md](lancement.md)
(checklist, plan de rollback à trois niveaux, procès-verbal de recette — P9-01).
