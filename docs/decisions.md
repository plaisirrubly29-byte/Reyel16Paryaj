# Registre des décisions

Décisions à valider avant le développement complet (§13 du document d'architecture).
Une décision non validée = la fonctionnalité concernée reste **désactivée**.

| # | Sujet | Tâche | Statut | Décision | Date | Validé par |
|---|---|---|---|---|---|---|
| D-01 | Territoire, cadre juridique, licences | P0-01 | 🟡 À décider | | | |
| D-02 | Fournisseur de données sportives et cotes (droits, limites, sandbox) | P0-02 | 🟡 Pressenti | **The Odds API** (accès détenu par Rubly) — à confirmer : droits d'usage pour des paris, quotas, sports couverts, résultats (scores) | | |
| D-03 | Sports, compétitions et marchés V1 | P0-03 | 🟡 À décider | | | |
| D-04 | Types de paris V1 : simple seulement ou combiné | P0-03 | 🟡 À décider | | | |
| D-05 | Politique changement de cote / annulation / report / abandon / correction | P0-04 | 🟡 À décider | | | |
| D-06 | Devise, arrondi, mises min/max, plafonds de gain | P0-05 | 🟡 À décider | | | |
| D-07 | Rôles admin, double validation, limites d'action | P0-06 | 🟡 À décider | | | |
| D-08 | Prestataires de paiement, frais, webhooks | P7-01 | ⛔ Bloqué | | | |
| D-09 | Âge, vérification d'identité, jeu responsable, protection des données | P0-01 | 🟡 À décider | | | |
| D-10 | Hébergement, disponibilité, sauvegardes, rétention | P0-07 | 🟡 À décider | | | |

## Décisions techniques déjà prises

| # | Décision | Raison |
|---|---|---|
| T-01 | Monolithe modulaire Laravel, API versionnée `/api/v1` | Coût opérationnel faible, transactions simples, extraction possible plus tard |
| T-02 | Monorepo `backend/` + `frontend/` | Une PR peut couvrir API + écran ; CI unique |
| T-03 | Frontend unique (site joueur + `/admin`) | Code partagé ; l'API reste la seule barrière de sécurité |
| T-04 | Montants en entier (unité mineure) via `App\Support\Money` | Pas d'erreur d'arrondi float |
| T-05 | Authentification Sanctum en **mode SPA** (cookie de session HttpOnly + CSRF), confirmé en P2-02 ; aucun jeton API en V1 | Pas de jeton exposé au JavaScript (XSS), standard Laravel pour un frontend first-party |
