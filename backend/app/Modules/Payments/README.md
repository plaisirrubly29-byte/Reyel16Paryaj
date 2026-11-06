# Module Payments Adapter

**Namespace :** `App\Modules\Payments`
**Livraison :** V2 (V2-B01 : socle commun ; V2-B02 : dépôts MonCash ; V2-B03 : retraits)
**Responsable :** Benginio Jean-Baptiste

## Responsabilités

- Interface commune des prestataires de paiement (`Contracts\PaymentProvider`) : ouvrir un paiement, vérifier
  et traduire un webhook.
- Règles communes (`Services\PaymentService`) : webhooks signés et idempotents, crédit unique au ledger, écarts mis
  en revue, expiration des dépôts non confirmés.
- Journal des transactions (`payment_transactions`) et des webhooks reçus (`webhook_events`, en ajout seul).

## Dépendances

- Wallet & Ledger (`LedgerService::providerDeposit`)
- Prestataires agréés : décision D-08 (V2-A02). Seul le faux prestataire existe aujourd'hui.

## Tables

- `payment_transactions` : une ligne par paiement (dépôt ou versement), statut, notre référence (`pay_…`), celle du
  prestataire, écriture du ledger, motif de revue, expiration
- `webhook_events` : chaque webhook reçu (corps brut, signature valide ou non, résultat), unique par
  (prestataire, identifiant d'événement)

## Routes

- `POST /api/v1/payments/webhooks/{provider}` : sans compte, signature vérifiée par le prestataire ; 404 si le
  prestataire n'est pas listé et activé dans `config/payments.php`
- Admin (module Admin, `ledger.view`) : `GET /api/v1/admin/payments`, `GET /api/v1/admin/payments/{id}`
- Joueur : à venir avec V2-B02 (déposer) et V2-B03 (retirer)

## Règles à respecter

- Aucun effet financier sans webhook à la signature vérifiée. Un webhook signé de plus de 5 minutes est refusé
  (`PAYMENTS_WEBHOOK_TOLERANCE_SECONDS`).
- Un même événement n'a qu'un effet : rejoué, il renvoie le même résultat ; reçu deux fois en parallèle, le verrou
  sur la transaction et la contrainte unique de `webhook_events` arrêtent le second. Le crédit porte la référence
  unique `payment:<référence>` au ledger.
- Succès avec un montant ou une devise différents : statut `review`, aucun crédit, revue par l'équipe.
- Dépôt non confirmé après 30 minutes (`PAYMENTS_DEPOSIT_EXPIRY_MINUTES`) : `expired` (commande `payments:expire`,
  toutes les 5 minutes). Un succès reçu ensuite est crédité (l'argent a quitté le compte du joueur) et marqué `late`.
- Le faux prestataire (`PAYMENTS_FAKE_ENABLED`) est refusé en production (`App\Support\ProductionSafety`).
- Ajouter un prestataire : une classe qui implémente `PaymentProvider`, une entrée dans `config/payments.php`
  (classe, libellé vu par le joueur, activation, secrets), et ses tests de signature.

## Organisation

```
Payments/
├── Console            # payments:expire
├── Contracts          # PaymentProvider
├── Enums              # PaymentStatus, PaymentDirection, WebhookOutcome
├── Http/Controllers   # webhook des prestataires
├── Models             # PaymentTransaction, WebhookEvent
├── Providers          # FakePaymentProvider (MonCash, NatCash : V2-B02)
├── Services           # PaymentService, PaymentProviderRegistry
├── Support            # PaymentInitiation, PaymentNotification
└── routes.php
```

Tests : `tests/Feature/Modules/Payments`.
