# Module Audit & Reporting

**Namespace :** `App\Modules\Audit`
**Livraison :** Phase 6
**Responsable :** _à assigner_

## Responsabilités

- Historique des actions sensibles (acteur, action, ressource, avant/après, motif).
- Exports autorisés, métriques et rapports opérationnels.

## Dépendances

- Tous les modules

## Tables (modèle de données initial)

- `admin_actions`
- `audit_logs`

## Routes prévues

- `GET /api/v1/admin/audit-logs`

## Règles à respecter

- Journal en ajout seulement (append-only).
- Accès limité et lui-même journalisé.

## Organisation

```
Audit/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_admin_actions_table`).
Tests : `tests/Feature/Modules/Audit` et `tests/Unit/Modules/Audit`.
