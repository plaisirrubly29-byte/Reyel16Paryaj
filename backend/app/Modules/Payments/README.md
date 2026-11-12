# Module Payments Adapter

**Namespace :** `App\Modules\Payments`
**Livraison :** V2 (V2-B01 : socle commun ; V2-B02 : dépôts du joueur, prestataire simulé en attendant MonCash ; V2-B03 : retraits)
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
- Joueur (connecté, compte actif) :
  - `GET /api/v1/payments/methods` : prestataires activés et montants acceptés (`PAYMENTS_DEPOSIT_MIN_MINOR` /
    `PAYMENTS_DEPOSIT_MAX_MINOR`) ;
  - `POST /api/v1/payments/deposits` (Idempotency-Key, 10 par minute) : ouvre le dépôt et renvoie `redirect_url`, la
    page du prestataire où le joueur confirme. Refusé pour un compte admin, un portefeuille gelé ou une limite de
    dépôt dépassée (les dépôts encore en attente comptent dans la limite) ;
  - `GET /api/v1/payments/deposits`, `GET /api/v1/payments/deposits/{reference}` : suivi de ses dépôts.
  - Retraits : V2-B03.
- Prestataire simulé (seulement si `fake` est activé, 404 sinon) : `GET|POST /api/v1/payments/fake/checkout/{reference}`,
  page où le joueur paie ou refuse. La décision part en webhook signé traité par `PaymentService` (même chemin qu'un
  vrai webhook), puis le joueur revient sur `FRONTEND_URL/wallet?depot={reference}`.

## Remplacer le prestataire simulé

Ajouter le vrai prestataire (ex. MonCash) comme décrit plus bas, l'activer, et désactiver `fake`
(`PAYMENTS_FAKE_ENABLED=false`). La page Solde liste les moyens activés et suit `redirect_url` ; rien d'autre ne
change côté frontend ni côté ledger. Le faux prestataire est de toute façon refusé en production.

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
