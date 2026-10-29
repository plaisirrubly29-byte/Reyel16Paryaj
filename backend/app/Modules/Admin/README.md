# Module Admin & Operations

**Namespace :** `App\Modules\Admin`
**Livraison :** Phase 6
**Responsable :** Rubly PLAISIR (utilisateurs, tickets, finance) · Benginio Jean-Baptiste (événements, marchés, résultats)

## Responsabilités

- Gestion des comptes (recherche, suspension avec motif).
- Événements & marchés : suspendre/masquer, voir origine et fraîcheur.
- Tickets, finance, paramètres (limites, règles), rapports.

## Dépendances

- RBAC (IdentityAccess)
- Audit

## Tables (modèle de données initial)

- `finance_corrections` (P6-04) : corrections financières à double validation.
- Sinon, lecture des tables des autres modules (tickets, ledger) — jamais d'écriture directe dans le ledger.

## Routes prévues

- `GET /api/v1/admin/events`, `GET /api/v1/admin/events/{id}` (P6-02, livrées) : origine, synchronisation, marchés de tous statuts
- `GET/PATCH /api/v1/admin/markets/{id}` (P6-02, livrées) : fraîcheur ; suspendre / rouvrir avec motif
  (écriture par `SportsData\Services\MarketAvailabilityService`, auditée `market.suspended` / `market.reopened`)
- `GET /api/v1/admin/users`, `GET/PATCH /api/v1/admin/users/{id}` (P6-03, livrées)
- Tickets et finance (P6-04, livrées) :
  - `GET /api/v1/admin/tickets`, `GET /api/v1/admin/tickets/{id}` — `tickets.view` (tous les rôles) ;
  - `GET /api/v1/admin/ledger`, `GET /api/v1/admin/finance/reconciliation`, `GET /api/v1/admin/finance/corrections` — `ledger.view` ;
  - `POST /api/v1/admin/finance/corrections` — `finance.correct` ;
  - `POST /api/v1/admin/finance/corrections/{id}/approve|reject` — `finance.approve`.

## Corrections financières (double validation)

1. Un administrateur **demande** la compensation d'une écriture, avec un motif : rien n'est encore écrit.
2. Un **autre** administrateur la **valide** (`LedgerService::reverse` passe l'écriture compensatoire) ou la **refuse**.
   Le demandeur ne peut jamais valider sa propre demande (`403 self_approval_forbidden`) ; il peut la retirer (refus).
3. Chaque étape est auditée avec son motif (`finance.correction_requested|approved|rejected`).

V1 : seules les écritures **sans ticket** (crédits simulés) sont corrigeables. Compenser la mise ou le gain d'un ticket
le rendrait incohérent avec son statut : ces erreurs passent par la correction de résultat (P5-06).

## Rapprochement

`ReconciliationService` (lecture seule) : totaux par devise et par type, et trois contrôles d'écarts —
chaîne des soldes de chaque portefeuille, net des écritures de chaque ticket (`gain versé ?? 0 - mise`),
écritures rattachées à un ticket inexistant.

## Règles à respecter

- RBAC strict ; motif obligatoire pour les actions sensibles ; tout est journalisé.
- Rôles séparés : Super Admin, Opérations, Finance, Support, Conformité.

## Organisation

```
Admin/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations`.
Tests : `tests/Feature/Modules/Admin` et `tests/Unit/Modules/Admin`.
