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
- Prestataire retenu : **Plop Plop** (MonCash, NatCash, Kashpaw, carte), documentation « API Paiement Marchand »
  v1.4 fournie par le prestataire. Le faux prestataire reste pour les tests et le développement.

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
  - Retraits (V2-B03) : `POST /api/v1/payments/withdrawals` (Idempotency-Key, 5 par minute ; `provider`, `method`
    moncash ou natcash, `recipient` à 8 chiffres, `amount_minor`), `GET /api/v1/payments/withdrawals`,
    `GET /api/v1/payments/withdrawals/{reference}`, `POST /api/v1/payments/withdrawals/{reference}/cancel` (avant envoi).
- Prestataire simulé (seulement si `fake` est activé, 404 sinon) : `GET|POST /api/v1/payments/fake/checkout/{reference}`,
  page où le joueur paie ou refuse. La décision part en webhook signé traité par `PaymentService` (même chemin qu'un
  vrai webhook), puis le joueur revient sur `FRONTEND_URL/wallet?depot={reference}`.

## Plop Plop (`PlopplopPaymentProvider`)

1. Ouverture : `POST api/paiement-marchand` (`client_id`, `refference_id` = notre référence `pay_…`, `montant` en HTG,
   `payment_method`, `all` par défaut). Le joueur est envoyé sur l'`url` renvoyée ; `transaction_id` est gardé comme
   référence du prestataire.
2. Webhook `transaction.created` / `transaction.status_changed` sur `/api/v1/payments/webhooks/plopplop`, signé
   `X-Webhook-Signature: sha256=<HMAC-SHA256 du corps brut avec le client_secret>`. Statuts : `ok` → crédité,
   `failed` → échoué, `no` / `pending` → en attente. Sans identifiant d'événement ni horodatage signé : l'identifiant
   retenu est `<événement>:<transaction_id>:<statut>`, si bien qu'un webhook rejoué n'a aucun nouvel effet.
3. Vérification de secours (`ChecksPaymentStatus`, `POST api/paiement-verify`) : le webhook n'est envoyé qu'une fois,
   sans nouvel essai. `payments:reconcile` (chaque minute) relit les dépôts en attente ou expirés des dernières 24 h,
   et le suivi du dépôt par le joueur (`GET /payments/deposits/{reference}`) relit son statut au plus toutes les
   10 secondes. Même règles qu'un webhook (montant contrôlé, crédit unique), journalisé dans `webhook_events`.
4. Retour du joueur : le `lien_retour` du compte marchand (`<frontend>/wallet`) reçoit `?refference_id=pay_…` ; la page
   Solde suit alors ce dépôt.

**Mise en service** (Staging puis Production) :
- `.env` : `PAYMENTS_PLOPPLOP_ENABLED=true`, `PAYMENTS_PLOPPLOP_CLIENT_ID`, `PAYMENTS_PLOPPLOP_CLIENT_SECRET` (saisis
  sur le serveur, jamais dans le dépôt), `PAYMENTS_FAKE_ENABLED=false` ; sans ces deux valeurs, la Production refuse de
  démarrer (`ProductionSafety`).
- Espace marchand Plop Plop : `webhook_url` = `https://<site>/api/v1/payments/webhooks/plopplop`, `lien_retour` =
  `https://<site>/wallet`, `client_secret` configuré (sinon aucun webhook n'est signé, donc aucun n'est accepté).
- Le scheduler doit tourner (`payments:reconcile`, `payments:expire`, `payments:payouts`).

## Retraits (`PayoutService`, `PayoutProvider`)

Versés **automatiquement**, sans validation manuelle (décision de Rubly du 2026-11-13, à la place de la double
validation du contrat V2-B03) :

1. Demande du joueur : compte joueur, KYC si `PAYMENTS_WITHDRAWALS_REQUIRE_KYC=true` (contrat `KycVerifier`, fourni par
   le module KYC ; en attendant, personne n'est vérifié), un seul retrait en cours, plafond sur 24 h glissantes, solde
   suffisant. Le montant est réservé par une écriture `withdrawal` (`payout:<référence>`). Statut joueur : `requested`.
2. `payments:payouts` (chaque minute) : passe le plus ancien retrait en `processing` **avant** l'appel, vérifie le solde
   du portefeuille marchand (`api/marchand/balance`), puis verse chez Plop Plop : jeton marchand (`api/auth/marchand`),
   jeton de retrait signé HMAC-SHA256 de `amount|method|recipient|reference|timestamp` avec le `client_secret`
   (`api/auth/marchand/withdrawal-token`), exécution (`api/withdraw/marchand`). Un versement toutes les 125 s
   (Plop Plop impose 120 s entre deux retraits par IP).
3. Résultat : versé → `paid` ; refusé par MonCash / NatCash → `failed` et montant rendu par une écriture `reversal` ;
   non exécuté (solde marchand insuffisant, délai, configuration) → remis en file ; sans réponse → reste `processing`
   et vérifié par sa référence (`api/withdraw/marchand/verify`) ; inconnu de Plop Plop → remis en file, sans risque de
   double versement (la référence est unique chez Plop Plop).
4. Annulation par le joueur tant qu'il est `requested` : montant rendu (`reversal`). Audit : `withdrawal.requested`,
   `withdrawal.cancelled`, `withdrawal.paid`, `withdrawal.failed`.

Les identifiants du versement (`provider_transaction_id`, référence MonCash / NatCash, frais) sont dans `metadata`, pas
dans `provider_reference` (même format que ceux des dépôts chez Plop Plop). Le faux prestataire verse aussitôt, sauf
vers un numéro qui finit par `0000` (refusé).

**Mise en service** : `PAYMENTS_WITHDRAWALS_ENABLED=true`, `PAYMENTS_WITHDRAWALS_PROVIDER=plopplop` (Plop Plop activé et
configuré), portefeuille marchand approvisionné ; bornes et plafond : `PAYMENTS_WITHDRAWAL_MIN_MINOR`,
`PAYMENTS_WITHDRAWAL_MAX_MINOR`, `PAYMENTS_WITHDRAWAL_DAILY_MAX_MINOR`.

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
├── Console            # payments:expire, payments:reconcile
├── Contracts          # PaymentProvider, ChecksPaymentStatus (vérification active)
├── Enums              # PaymentStatus, PaymentDirection, WebhookOutcome
├── Http/Controllers   # webhook des prestataires, dépôts du joueur, page du prestataire simulé
├── Models             # PaymentTransaction, WebhookEvent
├── Providers          # PlopplopPaymentProvider, FakePaymentProvider
├── Services           # PaymentService, PaymentProviderRegistry
├── Support            # PaymentInitiation, PaymentNotification, PaymentStatusCheck
└── routes.php
```

Tests : `tests/Feature/Modules/Payments`.
