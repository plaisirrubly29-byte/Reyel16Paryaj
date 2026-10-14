# Module Payments Adapter

**Namespace :** `App\Modules\Payments`
**Livraison :** Phase 7 (BLOQUÉ)
**Responsable :** _à assigner_

## Responsabilités

- Abstraction des prestataires de paiement.
- Références internes/externes, webhooks vérifiés, rapprochement.

## Dépendances

- Wallet & Ledger
- Prestataire agréé

## Tables (modèle de données initial)

- `payment_transactions`
- `webhook_events`

## Routes prévues

- (à définir après contrat prestataire)

## Règles à respecter

- ⚠️ Désactivé tant que la validation juridique, contractuelle et fournisseur n’est pas écrite.
- Vérifier signature, montant, devise, référence, date et état avant tout effet financier.

## Organisation

```
Payments/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_payment_transactions_table`).
Tests : `tests/Feature/Modules/Payments` et `tests/Unit/Modules/Payments`.
