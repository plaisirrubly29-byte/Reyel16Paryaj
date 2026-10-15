# Module Sports Data Adapter

**Namespace :** `App\Modules\SportsData`
**Livraison :** Phase 3
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte) — accès The Odds API

## Responsabilités

- Synchroniser sports, ligues, équipes, événements, marchés et cotes depuis le fournisseur contracté.
- Valider schéma, source, horodatage et état avant toute mise à jour.
- Détecter données périmées / panne fournisseur et déclencher la suspension.

## Dépendances

- Fournisseur de données
- Queue (Redis)
- Logs

## Tables (modèle de données initial)

- `sports`
- `competitions`
- `teams`
- `events`
- `markets`
- `selections`
- `odds_snapshots`

## Routes prévues

- (aucune route publique — jobs planifiés et webhooks signés)

## Fournisseur (P3-02)

| `SPORTS_DATA_PROVIDER` | Implémentation | Usage |
|---|---|---|
| `fake` | `Providers/FakeSportsDataProvider` | Développement et tests : données déterministes, panne et cotes périmées simulables |
| `theodds` | `Providers/TheOddsApiProvider` | The Odds API v4 (clé dans `SPORTS_DATA_API_KEY`) |

```php
$provider = app(SportsDataProvider::class);
$provider->sports();                 // gratuit
$provider->events('soccer_epl');     // coût = marchés × régions (1 crédit avec h2h + eu)
$provider->results('soccer_epl', 2); // 2 crédits (1 sans daysFrom)
```

- **Quota** : offre gratuite = 500 requêtes/mois. Le quota restant est lu dans les en-têtes `x-requests-*`
  et mémorisé ; sous `SPORTS_DATA_MIN_REMAINING`, les appels payants sont refusés (`quota_exhausted`).
- **Diagnostic sans coût** : `php artisan sports-data:check` (connexion, sports configurés, quota).
- **Erreurs** : `SportsDataException::$reason` = `unavailable`, `unauthorized`, `quota_exhausted`, `not_found`,
  `invalid_response` — base de la suspension automatique des marchés (P3-04).
- **Cotes** en chaîne décimale exacte (`"2.05"`), jamais en float.
- Les tests forcent `SPORTS_DATA_PROVIDER=fake` et une clé vide (`phpunit.xml`) : aucun test ne consomme le quota.

## Règles à respecter

- Le fournisseur est accédé via une interface (`Contracts/SportsDataProvider`) pour pouvoir utiliser un faux fournisseur en dev/test.
- Secrets fournisseur uniquement dans `.env` côté serveur.
- Synchronisation idempotente et relançable.

## Organisation

```
SportsData/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
├── Contracts
├── Jobs
├── Providers
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_sports_table`).
Tests : `tests/Feature/Modules/SportsData` et `tests/Unit/Modules/SportsData`.
