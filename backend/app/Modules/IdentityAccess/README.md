# Module Identity & Access

**Namespace :** `App\Modules\IdentityAccess`
**Livraison :** Phase 2
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte)

## Responsabilités

- Inscription, connexion, déconnexion, gestion de session (Laravel Sanctum).
- Récupération de compte et profil.
- Statut du compte (actif, suspendu, exclu).
- Rôles et permissions (RBAC) appliqués côté API.

## Dépendances

- Base de données
- Notifications

## Tables

| Table | Rôle |
|---|---|
| `users` | Compte : téléphone (identifiant de connexion, 8 chiffres), email optionnel, mot de passe haché, `status` (`AccountStatus` : active, suspended, excluded, closed) |
| `user_profiles` | Données personnelles (nom, date de naissance, pays) — 1-1 avec `users` |
| `roles` | Rôles administratifs (`RoleSlug`) — seedés par `RoleSeeder` |
| `permissions` | Permissions unitaires (`ressource.action`) |
| `role_permissions` | Rôle → permissions |
| `user_roles` | Utilisateur → rôles (+ `assigned_by`) |

Un joueur n’a aucun rôle. Le statut ne change jamais par affectation de masse (non `fillable`).
Comptes de démonstration (local) : `php artisan db:seed` — `joueur@example.com`, `super-admin@example.com`,
`operations@example.com`, `finance@example.com`, `support@example.com`, `compliance@example.com` (mot de passe `password`).

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
