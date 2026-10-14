# Module Sports Data Adapter

**Namespace :** `App\Modules\SportsData`
**Livraison :** Phase 3
**Responsable :** _à assigner_

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
