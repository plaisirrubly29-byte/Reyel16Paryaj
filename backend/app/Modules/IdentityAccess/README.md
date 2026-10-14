# Module Identity & Access

**Namespace :** `App\Modules\IdentityAccess`
**Livraison :** Phase 2
**Responsable :** _à assigner_

## Responsabilités

- Inscription, connexion, déconnexion, gestion de session (Laravel Sanctum).
- Récupération de compte et profil.
- Statut du compte (actif, suspendu, exclu).
- Rôles et permissions (RBAC) appliqués côté API.

## Dépendances

- Base de données
- Notifications

## Tables (modèle de données initial)

- `users`
- `user_profiles`
- `roles`
- `permissions`
- `user_roles`

## Routes prévues

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/me`

## Règles à respecter

- Mots de passe hachés (cast `hashed`), limitation des tentatives, pas d’énumération des comptes.
- MFA obligatoire pour les rôles administratifs sensibles.
- Aucun rôle ne peut s’accorder lui-même des permissions.

## Organisation

```
IdentityAccess/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_users_table`).
Tests : `tests/Feature/Modules/IdentityAccess` et `tests/Unit/Modules/IdentityAccess`.
