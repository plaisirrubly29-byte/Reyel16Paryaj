# Module Bet Slip & Tickets

**Namespace :** `App\Modules\Betting`
**Livraison :** Phase 4
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte)

## Responsabilités

- Valider sélection, marché, cote, mise, limites, solde et statut.
- Créer le ticket et figer les paramètres acceptés (cote, règles).
- Historique et statut des tickets du joueur.

## Dépendances

- Catalogue
- Wallet & Ledger
- Compliance

## Tables (P4-01)

- `bet_tickets` : référence publique unique (`R16-XXXXXXXXXX`), joueur, portefeuille, type (simple/combiné),
  statut, mise en unité mineure + devise, cote totale, gain potentiel, gain versé, version et instantané des règles.
- `bet_selections` : match, marché et sélection d'origine + **instantané** de ce qui a été accepté
  (nom du match, marché, ligne, issue, cote, version de cote). Une seule sélection par marché dans un ticket.

Garde-fous :

- Aucun float : montants en `bigInteger`, cotes en `decimal` (chaîne en PHP).
- Les conditions acceptées sont immuables (exception côté modèle) ; seuls le statut, le gain et la date de
  règlement évoluent. Un ticket ne se supprime jamais, ni le joueur ou le match qui en portent.
- Si la synchro retire une sélection du catalogue, `selection_id` passe à null : le règlement s'appuie sur
  le marché + la clé de sélection figée.

## Routes prévues

- `POST /api/v1/bets` (P4-03)
- `GET /api/v1/bets` (P4-04) : tickets du joueur connecté, plus récents d'abord, filtre `status`, paginé
- `GET /api/v1/bets/{id}` (P4-04) : reçu d'un ticket ; celui d'un autre joueur répond 404 (protection IDOR)

## Règles à respecter

- La cote envoyée par le frontend n’est jamais une autorité : le serveur recharge l’état du marché.
- Clé d’idempotence obligatoire : une requête répétée ne crée pas de second ticket.
- Création du ticket + réservation de mise dans une seule transaction SQL.

## Plafonds d'engagement (V2-D05)

- **Perte maximale de la maison** sur un match (`BETTING_MAX_EVENT_LIABILITY_MINOR`, 500 000 HTG) et sur un marché
  (`BETTING_MAX_MARKET_LIABILITY_MINOR`, 250 000 HTG), modifiables dans les paramètres de l'admin (groupe « Risque »).
  Un pari qui ferait dépasser l'un des deux est refusé : `422 risk_limit_exceeded` (`details.scope` event|market).
- Pire cas d'un marché : gains de l'issue la plus chère − mises des paris simples sur ce marché ; celui d'un match
  additionne ses marchés. Un **combiné** compte, pour son gain entier, sur l'issue choisie d'un match seulement quand
  toutes ses autres sélections sont gagnées ou annulées.
- Un pari qui **réduit** le pire cas reste accepté, même au-dessus d'un plafond qu'on vient de baisser.
- Concurrence : sous la moitié d'un plafond, les paris d'un même match passent en parallèle (test de charge P8-03) ;
  au-delà, le match est verrouillé et le calcul refait, les paris passent un par un (`ExposureGuard`).
- **Mise maximale par joueur** : posée par un administrateur (`PUT /admin/users/{id}/stake-limit`, permission
  `users.suspend`, motif, audit `user.stake_limit_changed`) ; elle ne fait que baisser la limite générale
  (`stake_out_of_range`, `details.max` = limite du joueur).

## Organisation

```
Betting/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_bet_tickets_table`).
Tests : `tests/Feature/Modules/Betting` et `tests/Unit/Modules/Betting`.
