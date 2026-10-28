# Module Audit & Reporting

**Namespace :** `App\Modules\Audit`
**Livraison :** Phase 6
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte)

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

## Utilisation (P6-01)

```php
// Dans la même transaction que l'action auditée :
$audit->record(AuditAction::UserSuspended, $user, before: ['status' => 'active'], after: ['status' => 'suspended'], reason: $reason);
```

- Ajouter chaque nouvelle action dans `Enums/AuditAction` ; `requiresReason()` impose un motif aux actions sensibles.
- `audit_logs` n'a pas de clé étrangère vers `users` (l'email de l'acteur est figé) : supprimer un compte ne modifie pas l'historique.
- Append-only : exception côté modèle + trigger PostgreSQL qui refuse UPDATE/DELETE.
- `admin_actions` (demandes en attente de double validation) arrivera avec P6-04 / P5-06.

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
