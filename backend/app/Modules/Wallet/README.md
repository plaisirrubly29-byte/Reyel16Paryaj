# Module Wallet & Ledger

**Namespace :** `App\Modules\Wallet`
**Livraison :** Phase 5
**Responsable :** _à assigner_

## Responsabilités

- Journal des crédits/débits, réservations de mise, gains, remboursements.
- Corrections uniquement par écritures compensatoires.
- Calcul du solde à partir du ledger.

## Dépendances

- Betting
- Payments (si autorisé)

## Tables (modèle de données initial)

- `wallets`
- `ledger_entries`

## Routes prévues

- `GET /api/v1/wallet`
- `GET /api/v1/wallet/transactions`

## Règles à respecter

- Montants en entier (unité mineure) via `App\Support\Money` — jamais de float.
- Aucune suppression ni modification d’une écriture existante.
- Aucune route ne permet au client de modifier le solde.

## Organisation

```
Wallet/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_wallets_table`).
Tests : `tests/Feature/Modules/Wallet` et `tests/Unit/Modules/Wallet`.
