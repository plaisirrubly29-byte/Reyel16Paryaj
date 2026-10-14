# Module Compliance & Responsible Play

**Namespace :** `App\Modules\Compliance`
**Livraison :** Phase 7
**Responsable :** _à assigner_

## Responsabilités

- Contrôles d’âge/éligibilité si requis.
- Limites de mise/dépôt, pause, auto-exclusion.
- Journal des contrôles.

## Dépendances

- IdentityAccess
- Admin

## Tables (modèle de données initial)

- `limits`
- `responsible_play_events`

## Routes prévues

- (à définir selon le cadre légal validé)

## Règles à respecter

- Les exigences dépendent du territoire autorisé (décision Phase 0).

## Organisation

```
Compliance/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_limits_table`).
Tests : `tests/Feature/Modules/Compliance` et `tests/Unit/Modules/Compliance`.
