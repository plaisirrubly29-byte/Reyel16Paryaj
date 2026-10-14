# Module Bet Slip & Tickets

**Namespace :** `App\Modules\Betting`
**Livraison :** Phase 4
**Responsable :** _à assigner_

## Responsabilités

- Valider sélection, marché, cote, mise, limites, solde et statut.
- Créer le ticket et figer les paramètres acceptés (cote, règles).
- Historique et statut des tickets du joueur.

## Dépendances

- Catalogue
- Wallet & Ledger
- Compliance

## Tables (modèle de données initial)

- `bet_tickets`
- `bet_selections`

## Routes prévues

- `POST /api/v1/bets`
- `GET /api/v1/bets`
- `GET /api/v1/bets/{id}`

## Règles à respecter

- La cote envoyée par le frontend n’est jamais une autorité : le serveur recharge l’état du marché.
- Clé d’idempotence obligatoire : une requête répétée ne crée pas de second ticket.
- Création du ticket + réservation de mise dans une seule transaction SQL.

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
