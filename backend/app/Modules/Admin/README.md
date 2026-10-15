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

- (utilise les tables des autres modules)

## Routes prévues

- `GET/PATCH /api/v1/admin/events/{id}`
- `GET/PATCH /api/v1/admin/markets/{id}`
- `GET /api/v1/admin/users`, `GET/PATCH /api/v1/admin/users/{id}` (P6-03, livrées)

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
