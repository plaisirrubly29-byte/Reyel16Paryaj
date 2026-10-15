# Module Wallet & Ledger

**Namespace :** `App\Modules\Wallet`
**Livraison :** Phase 5
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte)

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

## Utilisation (P5-02)

```php
$ledger = app(LedgerService::class);
$wallet = $ledger->walletFor($user);                                   // devise : config('wallet.currency')
$ledger->reserveStake($wallet, Money::ofMinor(5000, 'HTG'), "bet:{$bet->id}:stake", (string) $bet->id);
$ledger->payout($wallet, $gain, "bet:{$bet->id}:payout", (string) $bet->id);
$ledger->reverse($entry, "bet:{$bet->id}:payout:reversal", $motif, $admin);  // correction
```

- **Référence métier** = clé d'idempotence de l'écriture (`<objet>:<id>:<opération>`) : rejouer une opération
  (relance de job, double clic) renvoie l'écriture existante, sans double débit ni double paiement.
- Appeler le service **dans la transaction** de l'opération métier (ex. création du ticket + mise).
- `insufficient_funds` (422) si la mise dépasse le solde ; `wallet_frozen` (423) si le portefeuille est gelé.
- Le solde n'est stocké nulle part ailleurs que dans les écritures (`balance_after_minor` sert au contrôle).

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
