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
| `terms_acceptances` | Acceptation des textes d'information à l'inscription (V2-E01) : version, date, IP, navigateur. Ajout seul |
| `kyc_submissions` / `kyc_files` | Dossiers KYC (V2-C03) : pièce d'identité + selfie, décision ; fichiers chiffrés sur le disque `kyc` |
| `roles` | Rôles administratifs (`RoleSlug`) — seedés par `RoleSeeder` |
| `permissions` | Permissions unitaires (`ressource.action`) |
| `role_permissions` | Rôle → permissions |
| `user_roles` | Utilisateur → rôles (+ `assigned_by`) |

Inscription (V2-E01) : `terms_accepted: true` et `terms_version` égale à `identity.terms_version`
(`IDENTITY_TERMS_VERSION`, à garder égale à `LEGAL_VERSION` du frontend), sinon 422 ; l'acceptation est enregistrée
dans la transaction de l'inscription.

## KYC (V2-C03)

- **Joueur** : `GET /me/kyc` (statut, dernier dossier, motif d'un refus) ; `POST /me/kyc` (multipart : `document_type`
  cin | passport | driving_licence | nif, `front`, `back` exigé pour cin et driving_licence, `selfie`). Un seul dossier
  en attente ; après un refus, un nouveau dossier.
- **Fichiers** : chiffrés par l'application (`Crypt`, clé `APP_KEY`) avant écriture sur le disque privé `kyc`
  (`storage/app/kyc`, volume `kyc_documents` en Staging) ; empreinte SHA-256 de l'original en base. **Changer `APP_KEY`
  rend les documents illisibles** ; le volume est à sauvegarder avec la base (V2-F02).
- **Revue** : `kyc.view` et `kyc.approve` (Super Admin, Conformité, Support), `kyc.reject` (Super Admin, Conformité),
  motif obligatoire ; chaque ouverture d'un fichier est auditée (`kyc.document_viewed`).
- **Contrôles** (`KycService`) : tout retrait exige un KYC validé ; dépôts libres jusqu'à `KYC_DEPOSIT_THRESHOLD_MINOR`
  cumulés (paramètre « KYC » de l'admin) ; sinon `422 kyc_required`. Branchés sur les dépôts / retraits enregistrés par
  l'admin, à brancher aussi sur les paiements (V2-B02, V2-B03).

Un joueur n’a aucun rôle. Le statut ne change jamais par affectation de masse (non `fillable`).
Comptes de démonstration (local) : `php artisan db:seed` — `joueur@example.com`, `super-admin@example.com`,
`operations@example.com`, `finance@example.com`, `support@example.com`, `compliance@example.com` (mot de passe `password`).

## Routes prévues

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/me`

## RBAC (P2-04)

- Matrice : `config/permissions.php` (brouillon P0-06), synchronisée par `PermissionSeeder`.
- Route protégée : `->middleware(['auth:sanctum', 'active', 'permission:users.suspend'])`.
- Dans le code : `$user->hasPermission('ledger.view')` ou `Gate::allows('ledger.view')`.
- `active` coupe l'accès d'un compte suspendu même si sa session est ouverte.
- `permission` s'exécute avant la résolution des modèles de l'URL (403 sans révéler si l'identifiant existe).

## Protection contre les robots et limite des visiteurs (V2-F04, D-17)

- **Limite globale des visiteurs** : limiteur `api` (`AppServiceProvider`), 300 requêtes/min par IP pour un visiteur
  non connecté (`IDENTITY_API_GUEST_RATE_PER_MINUTE`, 0 = sans limite), 120/min par compte connecté. Les webhooks de
  paiement n'y sont pas soumis (`withoutMiddleware('throttle:api')`). Une requête refusée par `auth:sanctum` (401)
  n'est pas comptée : l'authentification passe avant la limite.
- **Vérification « humain »** : `Contracts/HumanVerifier`, implémentée par `Services/TurnstileVerifier` (appel HTTP à
  Cloudflare, sans paquet). Middleware `human` (`RequireHumanVerification`), **avant la validation** du formulaire
  (aucune énumération des comptes) :
  - `human:always` : inscription, mot de passe oublié ; Cloudflare injoignable → refus (`unavailable`) ;
  - `human:after-login-failures` : connexion, après 3 échecs en 15 min (`Services/LoginFailureCounter`, par
    identifiant saisi normalisé et par IP, que le compte existe ou non ; remis à zéro par une connexion réussie) ;
    Cloudflare injoignable → la connexion passe (décision du 2026-11-19), ses limites restant appliquées.
  - Refus : `422 captcha_failed`, `details.reason` = `required`, `invalid` ou `unavailable`.
  - Désactivée tant que `TURNSTILE_SECRET_KEY` est vide. Alerte d'exploitation `turnstile_unavailable` (Monitoring) ;
    en Production sans clé, avertissement `security.turnstile_disabled` dans les logs (au plus une fois par heure).
- Réutilisable pour l'envoi des codes SMS (V2-C01) : `->middleware('human:always')`.

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
