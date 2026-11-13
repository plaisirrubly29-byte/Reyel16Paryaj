# Registre des décisions

Décisions à valider avant le développement complet (§13 du document d'architecture).
Une décision non validée = la fonctionnalité concernée reste **désactivée**.

**Exception (2026-10-30)** : les décisions marquées ⏸ **Reportée** ne bloquent aucune fonctionnalité de la V1 (fonds fictifs).
Leur proposition, ou la valeur par défaut la plus protectrice, s'applique jusqu'à leur validation. Elles restent
**obligatoires avant tout argent réel**.

| # | Sujet | Tâche | Statut | Décision | Date | Validé par |
|---|---|---|---|---|---|---|
| D-01 | Territoire, cadre juridique, licences | P0-01 | ⏸ Reportée — proposition appliquée en V1 ([détail](decisions/D-01-D-09-cadre-juridique.md)) | Haïti seul ; V1 en fonds fictifs ; argent réel après licence écrite | | |
| D-02 | Fournisseur de données sportives et cotes (droits, limites, sandbox) | P0-02 | ✅ Validé ([détail](decisions/D-02-fournisseur-donnees.md)) | **The Odds API** ; offre gratuite en dev/Staging, offre payante ≥ 20 000 crédits/mois en production ; usage « paris » à confirmer par écrit avant l'argent réel | 2026-10-29 | Rubly PLAISIR |
| D-03 | Sports, compétitions et marchés V1 | P0-03 | ✅ Validé ([détail](decisions/D-03-D-04-catalogue-types-paris.md)) | 9 compétitions de football + NBA ; marchés 1X2 et plus/moins ; pas de paris en direct | 2026-10-29 | Rubly PLAISIR |
| D-04 | Types de paris V1 : simple seulement ou combiné | P0-03 | ✅ Validé ([détail](decisions/D-03-D-04-catalogue-types-paris.md)) | Simple et combiné (20 sélections max, une par match) | 2026-10-29 | Rubly PLAISIR |
| D-05 | Politique changement de cote / annulation / report / abandon / correction | P0-04 | ✅ Validé ([détail](decisions/D-05-cotes-annulations-corrections.md)) | Cote changée : refus + reconfirmation ; annulé/abandonné : cote 1 ; reporté > 48 h : cote 1 ; correction après règlement à double contrôle, 72 h max | 2026-10-29 | Rubly PLAISIR |
| D-06 | Devise, arrondi, mises min/max, plafonds de gain | P0-05 | ✅ Validé ([détail](decisions/D-06-devise-mises-plafonds.md)) | HTG fictifs ; mise 10 à 50 000 HTG ; gain max 1 000 000 HTG ; gain arrondi au centime inférieur | 2026-10-29 | Rubly PLAISIR |
| D-07 | Rôles admin, double validation, limites d'action | P0-06 | ✅ Validé ([détail](decisions/D-07-roles-admin.md)) | 5 rôles de la matrice ; double validation des corrections de résultat et financières ; MFA obligatoire ; 2 Super Admins au lancement | 2026-10-29 | Rubly PLAISIR |
| D-08 | Prestataires de paiement, frais, webhooks | P7-01 | ⏸ Reportée | Aucun prestataire en V1 (fonds fictifs) : dépôt et retrait affichés « Bientôt » | | |
| D-09 | Âge, vérification d'identité, jeu responsable, protection des données | P0-01 | ⏸ Reportée — proposition appliquée en V1 ([détail](decisions/D-01-D-09-cadre-juridique.md)) | 18 ans ; KYC avant tout dépôt réel ; jeu responsable dès la V1 | | |
| D-10 | Hébergement, disponibilité, sauvegardes, rétention | P0-07, V2-A03 | ⏸ Reportée — proposition appliquée au Staging et à la démo ; fournisseur de Production proposé ([détail](decisions/D-10-hebergement.md)) | Staging VPS Docker ; Prod VPS + PostgreSQL managé ; 99,5 %, RPO 15 min, RTO 4 h ; proposition V2-A03 : DigitalOcean (serveur + PostgreSQL managé, PITR 7 jours), ≈ 90–95 $/mois pour la Prod | | |
| D-11 | Jeux virtuels : football et courses | V2-D08 | 🟡 À décider — périmètre fixé ([détail](decisions/D-11-jeux-virtuels.md)) | Football virtuel et courses virtuelles ; prestataire tiers certifié ; avis juridique, prestataire et règles à obtenir ; jeux absents du site d'ici là | | |
| D-12 | Borlette : cadre juridique, règles des jeux, barème des gains, limites par boule | V2-D09 | 🟡 À décider — brouillon ([détail](decisions/D-12-borlette.md)) | Tirages et résultats déjà affichés (étape 1) ; vente de tickets désactivée tant que l'avis juridique, le barème et les limites ne sont pas validés par écrit | | |
| D-13 | Agents : rôle, portefeuille, documents, limites et commissions | V2-G01, V2-G02 | 🟡 À décider — options proposées ([détail](decisions/D-13-agents.md)) | Proposition : parrain (apporteur) seul au lancement, sans maniement d'argent ; encaissement en espèces après licence et D-08 ; point de vente reporté | | |
| D-16 | Supervision : suivi des erreurs, surveillance externe et canaux d'alerte | V2-F03 | 🟡 À décider — comparatif proposé ([détail](decisions/D-16-supervision.md)) | Proposition : Sentry Team (ou GlitchTip) ; UptimeRobot toutes les minutes ; healthchecks.io 2 + 2 min ; `monitoring:check` toutes les 2 min ; SMS pour les pannes graves | | |

## Décisions techniques déjà prises

| # | Décision | Raison |
|---|---|---|
| T-01 | Monolithe modulaire Laravel, API versionnée `/api/v1` | Coût opérationnel faible, transactions simples, extraction possible plus tard |
| T-02 | Monorepo `backend/` + `frontend/` | Une PR peut couvrir API + écran ; CI unique |
| T-03 | Frontend unique (site joueur + `/admin`) | Code partagé ; l'API reste la seule barrière de sécurité |
| T-04 | Montants en entier (unité mineure) via `App\Support\Money` | Pas d'erreur d'arrondi float |
| T-05 | Authentification Sanctum en **mode SPA** (cookie de session HttpOnly + CSRF), confirmé en P2-02 ; aucun jeton API en V1 | Pas de jeton exposé au JavaScript (XSS), standard Laravel pour un frontend first-party |
