# Plan de tâches — REYEL 16 PAYAJ V1

> Fichier généré depuis `scripts/github/tasks.json` — ne pas éditer à la main.
> Régénérer : `node scripts/github/bootstrap.mjs --markdown`

## Rôles

| Rôle | Périmètre | Membre(s) |
|---|---|---|
| `product` | Chef de projet / propriétaire — décisions, conformité, validation | |
| `backend-core` | Backend 1 — Identité, Wallet & Ledger, Règlement, Audit | |
| `backend-data` | Backend 2 — Données sportives, Catalogue, Tickets | |
| `frontend-public` | Frontend 1 — Site public et espace joueur | |
| `frontend-admin` | Frontend 2 — Panneau d'administration | |
| `devops-qa` | DevOps / QA — CI, environnements, tests E2E, sécurité | |

## Phase 0 — Décisions & conformité

**Critère de sortie :** Décisions consignées ; dépendances externes confirmées ou explicitement bloquées.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P0-01 | Valider le territoire, le cadre juridique et les licences | `product` | compliance | — |
| P0-02 | Choisir le fournisseur de données sportives et de cotes | `product` | sports-data | — |
| P0-03 | Définir sports, compétitions, marchés et types de paris de la V1 | `product` | catalogue | — |
| P0-04 | Politique de changement de cote, annulation, report et correction de résultat | `product` | betting | — |
| P0-05 | Devise, arrondi, mises min/max et plafonds de gain | `product` | wallet | — |
| P0-06 | Rôles administratifs, double validation et limites d'action | `product` | admin | — |
| P0-07 | Hébergement, disponibilité, sauvegardes et rétention | `product` | infra | — |

## Phase 1 — Architecture & socle

**Critère de sortie :** Application déployée en Staging ; tests et migrations fonctionnels.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P1-01 | Valider l'environnement Docker local pour toute l'équipe | `devops-qa` | infra | — |
| P1-02 | CI GitHub Actions verte et protection de la branche main | `devops-qa` | infra | — |
| P1-03 | Conventions API : format JSON, erreurs, pagination, OpenAPI | `backend-core` | core | — |
| P1-04 | Journalisation structurée et identifiant de requête | `devops-qa` | core | — |
| P1-05 | Socle frontend : design de base, gestion d'état serveur, gestion des erreurs API | `frontend-public` | core | — |
| P1-06 | Déploiement Staging automatisé | `devops-qa` | infra | P0-07 |

## Phase 2 — Identité & rôles

**Critère de sortie :** Tests d'accès et de session réussis.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P2-01 | Migrations utilisateurs, profils, rôles et permissions | `backend-core` | identity | — |
| P2-02 | API inscription, connexion, déconnexion, /me (Sanctum) | `backend-core` | identity | P2-01 |
| P2-03 | Récupération de compte sécurisée | `backend-core` | identity | P2-02 |
| P2-04 | RBAC : middleware, policies et protection contre l'auto-élévation | `backend-core` | identity | P2-01, P0-06 |
| P2-05 | MFA pour les rôles administratifs | `backend-core` | identity | P2-04 |
| P2-06 | Pages inscription, connexion, profil | `frontend-public` | identity | P2-02, P1-05 |
| P2-07 | Tests d'accès et de session | `devops-qa` | identity | P2-04 |

## Phase 3 — Données sportives

**Critère de sortie :** Données de sandbox cohérentes ; pannes et cotes périmées gérées.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P3-01 | Migrations sports, compétitions, équipes, événements, marchés, sélections, cotes | `backend-data` | sports-data | — |
| P3-02 | Interface SportsDataProvider + faux fournisseur | `backend-data` | sports-data | P0-02 |
| P3-03 | Jobs de synchronisation (queue) avec validation | `backend-data` | sports-data | P3-01, P3-02 |
| P3-04 | Règle de fraîcheur et suspension automatique des marchés | `backend-data` | sports-data | P3-03 |
| P3-05 | API catalogue public | `backend-data` | catalogue | P3-04 |
| P3-06 | Pages sports, compétitions, matchs et cotes | `frontend-public` | catalogue | P3-05 |

## Phase 4 — Coupon & tickets simulés

**Critère de sortie :** Tests de doublon, changement de cote et événement fermé réussis.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P4-01 | Migrations tickets et sélections de ticket | `backend-data` | betting | P3-01 |
| P4-02 | Middleware clé d'idempotence | `backend-data` | core | — |
| P4-03 | POST /bets : revalidation serveur et transaction atomique | `backend-data` | betting | P4-01, P4-02, P5-02, P0-04 |
| P4-04 | GET /bets et GET /bets/{id} | `backend-data` | betting | P4-01 |
| P4-05 | Coupon de pari et confirmation de changement de cote | `frontend-public` | betting | P4-03, P3-06 |
| P4-06 | Historique des tickets | `frontend-public` | betting | P4-04 |
| P4-07 | Tests d'acceptation tickets | `devops-qa` | betting | P4-03 |

## Phase 5 — Ledger simulé & règlement

**Critère de sortie :** Aucun double débit/gain ; historique vérifiable.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P5-01 | Migrations wallets et ledger_entries | `backend-core` | wallet | P0-05 |
| P5-02 | Service Ledger : écritures, réservation de mise, compensations | `backend-core` | wallet | P5-01 |
| P5-03 | API portefeuille en lecture | `backend-core` | wallet | P5-02 |
| P5-04 | Migrations résultats/règlements et import de résultats de test | `backend-data` | settlement | P3-02 |
| P5-05 | Service de règlement idempotent | `backend-core` | settlement | P5-02, P5-04, P4-03 |
| P5-06 | Correction de résultat auditée | `backend-core` | settlement | P5-05, P6-01 |
| P5-07 | Page portefeuille | `frontend-public` | wallet | P5-03 |

## Phase 6 — Administration

**Critère de sortie :** Matrice des permissions validée ; actions sensibles tracées.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P6-01 | Journal d'audit (admin_actions / audit_logs) | `backend-core` | audit | P2-04 |
| P6-02 | API admin événements et marchés | `backend-data` | admin | P6-01, P3-05 |
| P6-03 | API admin utilisateurs | `backend-data` | admin | P6-01 |
| P6-04 | API admin tickets et finance | `backend-core` | admin | P6-01, P5-02 |
| P6-05 | Admin : authentification, layout et vue d'ensemble | `frontend-admin` | admin | P2-05, P1-05 |
| P6-06 | Admin : écrans Événements & marchés et Résultats & règlement | `frontend-admin` | admin | P6-05, P6-02 |
| P6-07 | Admin : écrans Utilisateurs et Tickets | `frontend-admin` | admin | P6-05, P6-03, P6-04 |
| P6-08 | Admin : écrans Finance, Paramètres et Audit | `frontend-admin` | admin | P6-05, P6-04, P6-01 |
| P6-09 | Matrice des permissions validée par tests API | `devops-qa` | admin | P6-02, P6-03, P6-04 |

## Phase 7 — Intégrations autorisées

**Critère de sortie :** Tests contractuels et rapprochement approuvés.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P7-01 | ⛔ Payments Adapter (BLOQUÉ jusqu'à validation juridique) | `backend-core` | payments | P0-01, P5-02 |
| P7-02 | Jeu responsable : limites, pause, auto-exclusion | `backend-data` | compliance | P0-01, P4-03 |

## Phase 8 — QA & préparation production

**Critère de sortie :** Aucune anomalie bloquante ; checklist signée.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P8-01 | Tests E2E du parcours complet | `devops-qa` | qa | P5-05, P4-05 |
| P8-02 | Revue de sécurité | `devops-qa` | qa | — |
| P8-03 | Test de charge adapté | `devops-qa` | qa | — |
| P8-04 | Sauvegarde/restauration testée et procédure d'incident | `devops-qa` | infra | — |

## Phase 9 — Lancement contrôlé

**Critère de sortie :** Validation opérationnelle et plan de support actifs.

| ID | Tâche | Rôle | Module | Dépend de |
|---|---|---|---|---|
| P9-01 | Checklist de lancement et plan de rollback | `product` | infra | P8-01, P8-02, P8-04 |
| P9-02 | Monitoring et alertes de production | `devops-qa` | infra | — |

## Détail des critères d'acceptation

### P0-01 — Valider le territoire, le cadre juridique et les licences

Confirmer le territoire autorisé, les licences, restrictions territoriales et règles de protection des données (§8, §13).

- [ ] Décision écrite dans docs/decisions.md
- [ ] Liste des obligations (âge, KYC, jeu responsable) consignée

### P0-02 — Choisir le fournisseur de données sportives et de cotes

Comparer les fournisseurs, droits d'utilisation, limites d'appels, coûts et environnement sandbox.

- [ ] Fournisseur choisi et documenté
- [ ] Accès sandbox obtenu (clé stockée hors du dépôt)
- [ ] Documentation de l'API fournisseur liée dans docs/decisions.md

### P0-03 — Définir sports, compétitions, marchés et types de paris de la V1

Sports/compétitions/marchés inclus ; pari simple seulement ou combiné également.

- [ ] Liste fermée des sports et marchés V1
- [ ] Décision simple/combiné consignée

### P0-04 — Politique de changement de cote, annulation, report et correction de résultat

Règles à appliquer quand la cote change, quand un match est reporté/abandonné, ou quand un résultat est corrigé (§4.2, §4.3).

- [ ] Règles écrites avec exemples chiffrés
- [ ] Validées par le propriétaire du projet

### P0-05 — Devise, arrondi, mises min/max et plafonds de gain

Paramètres financiers de la V1.

- [ ] Devise(s), règle d'arrondi, min/max et plafonds consignés

### P0-06 — Rôles administratifs, double validation et limites d'action

Compléter docs/permissions-matrix.md (rôle × action × ressource).

- [ ] Matrice validée
- [ ] Actions nécessitant une double validation identifiées

### P0-07 — Hébergement, disponibilité, sauvegardes et rétention

Choisir l'hébergeur, objectifs de disponibilité, politique de sauvegarde et durée de conservation des données.

- [ ] Choix documenté avec coût estimé

### P1-01 — Valider l'environnement Docker local pour toute l'équipe

Vérifier docker compose (PostgreSQL, Redis, backend, queue, frontend) sur Windows/macOS/Linux, compléter le README si besoin.

- [ ] Chaque membre lance le projet en suivant le README
- [ ] GET /api/v1/health répond depuis le frontend

### P1-02 — CI GitHub Actions verte et protection de la branche main

Faire passer la CI, protéger main et develop (PR obligatoire, 1 revue, CI verte).

- [ ] CI verte sur main
- [ ] Push direct sur main impossible
- [ ] CODEOWNERS rempli avec les comptes de l'équipe

### P1-03 — Conventions API : format JSON, erreurs, pagination, OpenAPI

Définir le format de réponse et d'erreur (codes, messages), la pagination, et documenter dans docs/api/openapi.yaml.

- [ ] Format d'erreur unique appliqué via le handler d'exceptions
- [ ] Guide dans docs/api/README.md
- [ ] Swagger UI ou équivalent consultable

### P1-04 — Journalisation structurée et identifiant de requête

Logs JSON, middleware X-Request-Id propagé dans les logs et les jobs.

- [ ] Chaque log contient request_id
- [ ] Test du middleware

### P1-05 — Socle frontend : design de base, gestion d'état serveur, gestion des erreurs API

Choisir et installer la librairie de requêtes (ex. TanStack Query), composants de base (bouton, champ, tableau, alerte), affichage des erreurs ApiError.

- [ ] Composants de base dans src/shared/components
- [ ] Responsive mobile vérifié

### P1-06 — Déploiement Staging automatisé

Image de production, secrets séparés, déploiement depuis develop vers Staging, migrations automatiques.

- [ ] Staging accessible en HTTPS
- [ ] Procédure de déploiement et rollback documentée

### P2-01 — Migrations utilisateurs, profils, rôles et permissions

Tables users/user_profiles/roles/permissions/user_roles + statut du compte.

- [ ] Migrations réversibles
- [ ] Seeders des rôles : Super Admin, Opérations, Finance, Support, Conformité

### P2-02 — API inscription, connexion, déconnexion, /me (Sanctum)

Publier la config Sanctum, choisir le mode SPA (cookie) pour le frontend, limiter les tentatives.

- [ ] POST /auth/register, /auth/login, /auth/logout, GET /me
- [ ] Rate limiting et message identique compte inconnu/mauvais mot de passe
- [ ] Tests Feature

### P2-03 — Récupération de compte sécurisée

Réinitialisation de mot de passe par lien à usage unique et expirant.

- [ ] Pas d'énumération des comptes
- [ ] Tests

### P2-04 — RBAC : middleware, policies et protection contre l'auto-élévation

Vérification des permissions côté API ; un rôle ne peut pas s'accorder des permissions.

- [ ] Middleware permission:xxx
- [ ] Tests d'accès direct par identifiant (IDOR)

### P2-05 — MFA pour les rôles administratifs

TOTP obligatoire pour les comptes admin.

- [ ] Accès admin refusé sans MFA
- [ ] Codes de secours

### P2-06 — Pages inscription, connexion, profil

Formulaires connectés à l'API, gestion de session et routes protégées.

- [ ] Erreurs de validation affichées
- [ ] Redirection si non connecté

### P2-07 — Tests d'accès et de session

Couverture des scénarios : expiration, révocation, rôles, accès direct.

- [ ] Suite de tests verte en CI

### P3-01 — Migrations sports, compétitions, équipes, événements, marchés, sélections, cotes

Références internes et externes, statut, version de cote, horodatage de synchronisation (§5).

- [ ] Index événement/statut
- [ ] Identifiant fournisseur unique par entité

### P3-02 — Interface SportsDataProvider + faux fournisseur

Contrat dans app/Modules/SportsData/Contracts ; implémentation Fake pour dev/tests ; implémentation réelle du fournisseur sandbox.

- [ ] Sélection du fournisseur via SPORTS_DATA_PROVIDER
- [ ] Tests avec le faux fournisseur

### P3-03 — Jobs de synchronisation (queue) avec validation

Jobs planifiés idempotents ; validation schéma, source, horodatage, état.

- [ ] Relance sans doublon
- [ ] Erreurs fournisseur journalisées

### P3-04 — Règle de fraîcheur et suspension automatique des marchés

Marché suspendu/masqué si cote périmée, incohérente ou panne fournisseur (§4.1).

- [ ] Seuil de fraîcheur configurable
- [ ] Tests panne fournisseur

### P3-05 — API catalogue public

GET /sports, /competitions, /events, /events/{id}/markets avec filtrage des événements fermés.

- [ ] Cotes périmées jamais exposées
- [ ] Documenté dans OpenAPI

### P3-06 — Pages sports, compétitions, matchs et cotes

Navigation sport → compétition → match → marchés ; ajout d'une sélection au coupon.

- [ ] Responsive
- [ ] État 'marché suspendu' affiché

### P4-01 — Migrations tickets et sélections de ticket

bet_tickets/bet_selections : référence unique, mise (entier), cote acceptée, instantané des règles.

- [ ] Référence unique contrainte en base
- [ ] Pas de float

### P4-02 — Middleware clé d'idempotence

En-tête Idempotency-Key obligatoire sur les routes à effet ; même clé = même réponse.

- [ ] Requête rejouée ne crée rien de nouveau
- [ ] Tests concurrence

### P4-03 — POST /bets : revalidation serveur et transaction atomique

Recharger le marché, revalider cote/limites/éligibilité/fonds, appliquer la politique de changement de cote, créer le ticket + réserver la mise (§4.2).

- [ ] Cote du client jamais utilisée comme autorité
- [ ] Reçu immuable retourné
- [ ] Tests doublon, cote changée, événement fermé

### P4-04 — GET /bets et GET /bets/{id}

Historique et détail des tickets du joueur connecté uniquement.

- [ ] Accès au ticket d'un autre joueur refusé (test IDOR)

### P4-05 — Coupon de pari et confirmation de changement de cote

Sélections, mise, gain potentiel indicatif, envoi avec Idempotency-Key, gestion du refus/nouvelle cote.

- [ ] Double clic ne crée pas deux tickets
- [ ] Message clair si cote changée

### P4-06 — Historique des tickets

Liste et détail des tickets avec statut.

- [ ] Pagination
- [ ] Statuts lisibles

### P4-07 — Tests d'acceptation tickets

Critères §11 : marché fermé, cote périmée, doublon, erreur fournisseur.

- [ ] Tous les critères §11 liés aux tickets couverts

### P5-01 — Migrations wallets et ledger_entries

Montants en bigInteger (unité mineure) + devise ; référence métier unique ; append-only.

- [ ] Contrainte d'unicité sur la référence métier
- [ ] Aucune route de modification de solde

### P5-02 — Service Ledger : écritures, réservation de mise, compensations

Service unique pour toute écriture financière ; utilise App\Support\Money.

- [ ] Solde = somme des écritures
- [ ] Corrections uniquement par écritures compensatoires
- [ ] Tests unitaires

### P5-03 — API portefeuille en lecture

GET /wallet et /wallet/transactions.

- [ ] Lecture privée du propriétaire uniquement

### P5-04 — Migrations résultats/règlements et import de résultats de test

Source, référence externe, version, historique de correction.

- [ ] Résultats de test importables via le faux fournisseur

### P5-05 — Service de règlement idempotent

Calcul du statut à partir de la cote et des règles figées ; écriture ledger idempotente (§4.3).

- [ ] Relance ne paie jamais deux fois (test)
- [ ] Job relançable

### P5-06 — Correction de résultat auditée

Réévaluation et écritures compensatoires en cas de résultat corrigé ; double contrôle.

- [ ] Historique complet consultable
- [ ] Action auditée

### P5-07 — Page portefeuille

Solde (fourni par l'API) et historique des transactions.

- [ ] Aucun calcul de solde côté client

### P6-01 — Journal d'audit (admin_actions / audit_logs)

Acteur, action, ressource, avant/après, motif ; service réutilisable par tous les modules.

- [ ] Append-only
- [ ] Motif obligatoire pour les actions sensibles

### P6-02 — API admin événements et marchés

Consulter, suspendre/masquer un marché, voir origine et fraîcheur.

- [ ] RBAC testé
- [ ] Actions auditées

### P6-03 — API admin utilisateurs

Recherche, consultation du statut, suspension autorisée avec motif.

- [ ] Données minimales pour le rôle Support
- [ ] Actions auditées

### P6-04 — API admin tickets et finance

Recherche de tickets, ledger, rapprochement, écarts ; double validation pour corrections critiques.

- [ ] Rôle Finance séparé
- [ ] Double validation testée

### P6-05 — Admin : authentification, layout et vue d'ensemble

Connexion admin (avec MFA), menu selon permissions, tableau de bord (état synchro, alertes, opérations en attente).

- [ ] Menu filtré selon les permissions reçues de l'API

### P6-06 — Admin : écrans Événements & marchés et Résultats & règlement

Liste/filtre des événements, suspension/masquage d’un marché avec motif, suivi des résultats et règlements.

- [ ] Motif demandé avant chaque action sensible
- [ ] Origine et fraîcheur des données visibles

### P6-07 — Admin : écrans Utilisateurs et Tickets

Recherche de comptes, suspension avec motif ; recherche de tickets par référence, statut, date, utilisateur.

- [ ] Tableaux filtrables et paginés
- [ ] Données minimales selon le rôle

### P6-08 — Admin : écrans Finance, Paramètres et Audit

Ledger, rapprochement, écarts ; limites et configuration ; journal d’audit et exports.

- [ ] Double validation visible pour les corrections critiques
- [ ] Exports journalisés

### P6-09 — Matrice des permissions validée par tests API

Un test par ligne de docs/permissions-matrix.md.

- [ ] Matrice 100 % couverte

### P7-01 — Payments Adapter (BLOQUÉ jusqu'à validation juridique)

Interface prestataire, webhooks signés, anti-rejeu, rapprochement. Ne pas activer de flux réel.

- [ ] Validation juridique et contractuelle écrite
- [ ] Tests en sandbox uniquement

### P7-02 — Jeu responsable : limites, pause, auto-exclusion

Selon les exigences du territoire validé.

- [ ] Limites appliquées à POST /bets
- [ ] Événements journalisés

### P8-01 — Tests E2E du parcours complet

Compte → événement → coupon → ticket simulé → résultat test → règlement simulé (Playwright).

- [ ] E2E exécuté en CI sur Staging

### P8-02 — Revue de sécurité

OWASP : CORS strict, CSRF, XSS, injection, en-têtes, secrets, dépendances.

- [ ] Rapport avec corrections des points bloquants

### P8-03 — Test de charge adapté

Catalogue et POST /bets sous charge attendue.

- [ ] Objectifs de temps de réponse atteints

### P8-04 — Sauvegarde/restauration testée et procédure d'incident

Restauration dans un environnement contrôlé ; procédure documentée.

- [ ] Restauration réussie et chronométrée
- [ ] Runbook d'incident

### P9-01 — Checklist de lancement et plan de rollback

Checklist signée, procès-verbal de recette, limites connues.

- [ ] Checklist signée

### P9-02 — Monitoring et alertes de production

Alertes : erreurs API, retard de synchronisation, échecs de règlement, anomalies financières.

- [ ] Alertes testées
- [ ] Astreinte définie
