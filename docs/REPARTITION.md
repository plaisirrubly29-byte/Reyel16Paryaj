# Répartition des tâches par membre de l'équipe

> Généré depuis `scripts/github/tasks.json` — ne pas éditer à la main.
> Pour changer un responsable : modifier `owner` dans tasks.json, puis `node scripts/github/bootstrap.mjs --markdown`.

Les deux développeurs sont **full-stack** : chacun livre le backend **et** les écrans de son domaine,
ce qui limite les conflits de fichiers. Les tests croisés (P2-07, P4-07, P6-09) et la revue de sécurité (P8-02)
sont confiés à l'autre développeur que celui qui a écrit le code.

Le chef de projet est aussi développeur : il garde les décisions qui l'engagent (juridique, hébergement, lancement)
et valide les livrables de conception de l'analyste avant qu'ils ne débloquent les tâches de développement.
L'analyste conseil n'a aucune tâche : il relit les choix de conception et conseille l'équipe.

| Membre | Rôle | GitHub | Domaine | Tâches |
|---|---|---|---|---|
| **Rubly PLAISIR** | Chef de projet & développeur full-stack | @plaisirrubly29-byte | Pilotage (juridique, hébergement, lancement) + Compte, argent & administration — Identité/RBAC/MFA, Wallet & Ledger, Règlement, Audit, admin Utilisateurs/Tickets/Finance, CI & Staging | 26 |
| **Benginio Jean-Baptiste** | Développeur full-stack | @benginio | Sports, paris & opérations — Données sportives, Catalogue, Coupon & tickets, admin Événements/Résultats, Docker, logs, E2E, charge, sauvegardes, monitoring, revue de sécurité | 26 |
| **Widsondelne7376** | Analyste — conception | @Widsondelne7376 | Conception fonctionnelle — règles métier V1 (catalogue, cotes, paramètres financiers, rôles admin), étude du fournisseur de données | 5 |
| **Marckendy Fevrier** | Analyste conseil (neutre) | @MarckendyFevrier | Assiste et conseille l’équipe (revue des choix de conception, avis sur les livrables). Aucune tâche assignée. | — |

## Vagues

Une vague = un lot de travail à terminer avant de passer au suivant (durée à fixer selon la vitesse de l'équipe,
ex. 1 à 2 semaines). Les deux développeurs avancent dans la même vague en parallèle.

- **Vague 0** — Décisions (en parallèle, à démarrer tout de suite)
- **Vague 1** — Socle
- **Vague 2** — Identité & synchronisation des données
- **Vague 3** — Ledger, audit & catalogue
- **Vague 4** — Paris simulés
- **Vague 5** — Règlement & administration
- **Vague 6** — Finitions admin, QA & préparation production

## Rubly PLAISIR — Chef de projet & développeur full-stack (@plaisirrubly29-byte)

_Pilotage (juridique, hébergement, lancement) + Compte, argent & administration — Identité/RBAC/MFA, Wallet & Ledger, Règlement, Audit, admin Utilisateurs/Tickets/Finance, CI & Staging_

| Vague | ID | Tâche | Attend (autre responsable) |
|---|---|---|---|
| 0 | P0-01 | Valider le territoire, le cadre juridique et les licences | — |
| 0 | P0-07 | Hébergement, disponibilité, sauvegardes et rétention | — |
| 1 | P1-02 | CI GitHub Actions verte et protection de la branche main | — |
| 1 | P1-03 | Conventions API : format JSON, erreurs, pagination, OpenAPI | — |
| 1 | P2-01 | Migrations utilisateurs, profils, rôles et permissions | — |
| 2 | P2-02 | API inscription, connexion, déconnexion, /me (Sanctum) | — |
| 2 | P2-03 | Récupération de compte sécurisée | — |
| 2 | P2-04 | RBAC : middleware, policies et protection contre l'auto-élévation | P0-06 (Widsondelne7376) |
| 2 | P2-06 | Pages inscription, connexion, profil | P1-05 (Benginio Jean-Baptiste) |
| 2 | P4-02 | Middleware clé d'idempotence | — |
| 3 | P2-05 | MFA pour les rôles administratifs | — |
| 3 | P5-01 | Migrations wallets et ledger_entries | P0-05 (Widsondelne7376) |
| 3 | P5-02 | Service Ledger : écritures, réservation de mise, compensations | — |
| 3 | P5-03 | API portefeuille en lecture | — |
| 3 | P6-01 | Journal d'audit (admin_actions / audit_logs) | — |
| 4 | P1-06 | Déploiement Staging automatisé | — |
| 4 | P4-07 | Tests d'acceptation tickets | P4-03 (Benginio Jean-Baptiste) |
| 4 | P5-07 | Page portefeuille | — |
| 5 | P5-05 | Service de règlement idempotent | P5-04 (Benginio Jean-Baptiste), P4-03 (Benginio Jean-Baptiste) |
| 5 | P5-06 | Correction de résultat auditée | — |
| 5 | P6-03 | API admin utilisateurs | — |
| 5 | P6-04 | API admin tickets et finance | — |
| 6 | P6-07 | Admin : écrans Utilisateurs et Tickets | P6-05 (Benginio Jean-Baptiste) |
| 6 | P6-08 | Admin : écrans Finance, Paramètres et Audit | P6-05 (Benginio Jean-Baptiste) |
| 6 | P7-01 | ⛔ Payments Adapter (BLOQUÉ jusqu'à validation juridique) | — |
| 6 | P9-01 | Checklist de lancement et plan de rollback | P8-01 (Benginio Jean-Baptiste), P8-02 (Benginio Jean-Baptiste), P8-04 (Benginio Jean-Baptiste) |

## Benginio Jean-Baptiste — Développeur full-stack (@benginio)

_Sports, paris & opérations — Données sportives, Catalogue, Coupon & tickets, admin Événements/Résultats, Docker, logs, E2E, charge, sauvegardes, monitoring, revue de sécurité_

| Vague | ID | Tâche | Attend (autre responsable) |
|---|---|---|---|
| 1 | P1-01 | Valider l'environnement Docker local pour toute l'équipe | — |
| 1 | P1-04 | Journalisation structurée et identifiant de requête | — |
| 1 | P1-05 | Socle frontend : design de base, gestion d'état serveur, gestion des erreurs API | — |
| 1 | P3-01 | Migrations sports, compétitions, équipes, événements, marchés, sélections, cotes | — |
| 2 | P3-02 | Interface SportsDataProvider + faux fournisseur | P0-02 (Widsondelne7376) |
| 2 | P3-03 | Jobs de synchronisation (queue) avec validation | — |
| 2 | P3-04 | Règle de fraîcheur et suspension automatique des marchés | — |
| 3 | P2-07 | Tests d'accès et de session | P2-04 (Rubly PLAISIR) |
| 3 | P3-05 | API catalogue public | — |
| 3 | P3-06 | Pages sports, compétitions, matchs et cotes | — |
| 3 | P4-01 | Migrations tickets et sélections de ticket | — |
| 3 | P4-04 | GET /bets et GET /bets/{id} | — |
| 3 | P5-04 | Migrations résultats/règlements et import de résultats de test | — |
| 4 | P4-03 | POST /bets : revalidation serveur et transaction atomique | P4-02 (Rubly PLAISIR), P5-02 (Rubly PLAISIR), P0-04 (Widsondelne7376) |
| 4 | P4-05 | Coupon de pari et confirmation de changement de cote | — |
| 4 | P4-06 | Historique des tickets | — |
| 5 | P6-02 | API admin événements et marchés | P6-01 (Rubly PLAISIR) |
| 5 | P6-05 | Admin : authentification, layout et vue d'ensemble | P2-05 (Rubly PLAISIR) |
| 5 | P6-06 | Admin : écrans Événements & marchés et Résultats & règlement | — |
| 6 | P6-09 | Matrice des permissions validée par tests API | P6-03 (Rubly PLAISIR), P6-04 (Rubly PLAISIR) |
| 6 | P7-02 | Jeu responsable : limites, pause, auto-exclusion | P0-01 (Rubly PLAISIR) |
| 6 | P8-01 | Tests E2E du parcours complet | P5-05 (Rubly PLAISIR) |
| 6 | P8-02 | Revue de sécurité | — |
| 6 | P8-03 | Test de charge adapté | — |
| 6 | P8-04 | Sauvegarde/restauration testée et procédure d'incident | — |
| 6 | P9-02 | Monitoring et alertes de production | — |

## Widsondelne7376 — Analyste — conception (@Widsondelne7376)

_Conception fonctionnelle — règles métier V1 (catalogue, cotes, paramètres financiers, rôles admin), étude du fournisseur de données_

| Vague | ID | Tâche | Attend (autre responsable) |
|---|---|---|---|
| 0 | P0-02 | Choisir le fournisseur de données sportives et de cotes | — |
| 0 | P0-03 | Définir sports, compétitions, marchés et types de paris de la V1 | — |
| 0 | P0-04 | Politique de changement de cote, annulation, report et correction de résultat | — |
| 0 | P0-05 | Devise, arrondi, mises min/max et plafonds de gain | — |
| 0 | P0-06 | Rôles administratifs, double validation et limites d'action | — |

## Marckendy Fevrier — Analyste conseil (neutre) (@MarckendyFevrier)

Assiste et conseille l’équipe (revue des choix de conception, avis sur les livrables). Aucune tâche assignée.
