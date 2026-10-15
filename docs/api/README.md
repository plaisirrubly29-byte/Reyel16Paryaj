# Conventions de l'API v1

Contrat de référence : [`openapi.yaml`](openapi.yaml). Toute nouvelle route y est documentée **dans la même PR**.

## Consulter la documentation (Swagger UI)

```bash
npx --yes http-server docs/api -p 8081 -c-1   # depuis la racine du dépôt
# puis ouvrir http://localhost:8081
```

Tout serveur de fichiers statiques convient (ex. XAMPP : `http://localhost/<chemin du dépôt>/docs/api/`).
Vérifier le fichier : `npx --yes @redocly/cli@1 lint docs/api/openapi.yaml` (exécuté aussi par la CI).

## Généralités

| Règle | Valeur |
|---|---|
| URL de base | `/api/v1` (version dans le chemin ; une rupture de contrat = `/api/v2`) |
| Format | JSON uniquement. Envoyer `Accept: application/json` |
| Noms de champs | `snake_case` |
| Dates | ISO 8601 avec fuseau, ex. `2026-10-15T18:30:00+00:00` |
| Identifiants | chaînes opaques côté client (ne pas faire de calcul dessus) |
| Montants | objet `Money` : `{ "minor": 10050, "currency": "HTG" }` — entier en unité mineure, **jamais** de décimal |
| Cotes | chaîne décimale, ex. `"2.15"` (pas de `float` JSON, pour éviter les arrondis) |
| Opérations à effet | en-tête `Idempotency-Key` (UUID) obligatoire — voir ci-dessous |

## Réponses en succès

Une ressource :

```json
{ "data": { "id": "42", "email": "joueur@example.com" } }
```

Une liste paginée (`?page=2&per_page=20`, `per_page` borné à 100) :

```json
{
  "data": [ { "id": "1" }, { "id": "2" } ],
  "links": { "first": "…?page=1", "last": "…?page=5", "prev": "…?page=1", "next": "…?page=3" },
  "meta": { "current_page": 2, "per_page": 20, "total": 97, "last_page": 5, "from": 21, "to": 40, "path": "…" }
}
```

Côté Laravel : `XxxResource::collection($query->paginate(Pagination::perPage($request)))`
(`App\Support\Http\Pagination`).

Statuts utilisés : `200` lecture/modification, `201` création, `204` sans contenu (ex. déconnexion).

## Réponses en erreur (format unique)

Toutes les erreurs de `/api/*` ont la même forme, appliquée par le handler d'exceptions
(`App\Support\Http\ApiErrorRenderer`, branché dans `bootstrap/app.php`) :

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Les données envoyées sont invalides.",
    "details": { "email": ["Le champ email est obligatoire."] }
  }
}
```

- `code` : identifiant **stable** en `snake_case`. Le frontend décide de son comportement sur `code`, jamais sur `message`.
- `message` : texte lisible (français), affichable tel quel. Les erreurs 5xx ne révèlent jamais de détail interne.
- `details` : optionnel. Pour `validation_failed`, un tableau de messages par champ.

### Codes génériques

| HTTP | `code` | Quand |
|---|---|---|
| 400 | `bad_request` | Requête mal formée |
| 401 | `unauthenticated` | Session absente ou expirée |
| 403 | `forbidden` | Permission refusée (RBAC) |
| 404 | `not_found` | Ressource absente **ou** appartenant à un autre utilisateur (pas de fuite d'existence) |
| 405 | `method_not_allowed` | Verbe HTTP non supporté |
| 409 | `conflict` | Conflit d'état (codes métier plus précis ci-dessous) |
| 419 | `csrf_token_mismatch` | Jeton CSRF absent/expiré (mode SPA Sanctum) |
| 422 | `validation_failed` | Entrées invalides (`details` par champ) |
| 428 | `precondition_required` | En-tête obligatoire manquant (ex. `Idempotency-Key`) |
| 429 | `too_many_requests` | Limitation de débit — respecter l'en-tête `Retry-After` |
| 500 | `server_error` | Erreur interne (journalisée côté serveur) |
| 503 | `service_unavailable` | Maintenance ou dépendance indisponible |

### Codes métier

Les modules lèvent `App\Support\Http\ApiException` avec un code propre :

```php
throw new ApiException('odds_changed', 'La cote a changé.', 409, ['selection_id' => '7', 'odds' => '2.15']);
```

Les codes métier sont listés ici au fur et à mesure de leur création :

| HTTP | `code` | Module | Sens |
|---|---|---|---|
| 409 | `odds_changed` | Betting | La cote a changé : confirmation requise (`details` = nouvelle cote) |
| 409 | `idempotency_key_reused` | Core | Même `Idempotency-Key` avec un contenu différent |
| 409 | `idempotency_request_in_progress` | Core | Requête identique encore en cours de traitement |
| 403 | `mfa_required` | Identity | Rôle administratif sans MFA validé |
| 423 | `account_suspended` | Identity | Compte suspendu ou exclu |

## Idempotence

Toute route qui crée un effet (pari, écriture financière, action admin sensible) exige
`Idempotency-Key: <uuid>`. Le client génère **une clé par intention** (ex. un clic sur « Valider le pari »)
et la réutilise s'il relance la même requête après une erreur réseau.

- Même clé + même contenu → la réponse d'origine est renvoyée (en-tête `Idempotent-Replayed: true`), rien n'est recréé.
- Même clé + contenu différent → `409 idempotency_key_reused`.
- En-tête absent → `428 precondition_required`.

## Authentification

Mode SPA de Sanctum (cookie de session `HttpOnly` + CSRF) pour le frontend first-party :
1. `GET /sanctum/csrf-cookie` (hors `/api/v1`) ;
2. requêtes suivantes avec `credentials: 'include'` et l'en-tête `X-XSRF-TOKEN`.

Détails des routes : section `Auth` d'[`openapi.yaml`](openapi.yaml).
