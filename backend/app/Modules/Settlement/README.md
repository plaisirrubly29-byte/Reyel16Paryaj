# Module Settlement

**Namespace :** `App\Modules\Settlement`
**Livraison :** Phase 5
**Responsable :** _à assigner_

## Responsabilités

- Appliquer les résultats validés aux tickets concernés.
- Conserver source, référence externe, horodatage et historique des corrections.
- Réévaluation auditée en cas de résultat contesté.

## Dépendances

- Résultats
- Betting
- Wallet & Ledger
- Audit

## Tables (modèle de données initial)

- `results` : versions successives du résultat d'un match (ajout seul, `supersedes_id` vers la version remplacée).
- `settlements` : règlement de chaque ticket, par révision (ajout seul).
- `bet_selections.result_id` : version de résultat qui a réglé la sélection.

## Commandes

- `php artisan settlement:import-results` : importe les scores des matchs terminés (planifiée selon
  `SPORTS_DATA_RESULTS_CRON`) ; sans `--competition`, n'interroge que les compétitions qui attendent un résultat.
- En test et en développement : `FakeSportsDataProvider::setResult('fake-1', '2', '1')` publie un score.

## Routes prévues

- (déclenché par jobs et par l’admin)

## Règles à respecter

- Règlement idempotent : une relance ne paie jamais deux fois.
- Calcul basé sur la cote et la version de règles enregistrées au moment de l’acceptation.

## Organisation

```
Settlement/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_results_table`).
Tests : `tests/Feature/Modules/Settlement` et `tests/Unit/Modules/Settlement`.
