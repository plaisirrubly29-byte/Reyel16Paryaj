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
- `result_corrections` (P5-06) : demandes de correction d'un résultat déjà réglé, à double contrôle.

## Commandes

- `php artisan settlement:import-results` : importe les scores des matchs terminés (planifiée selon
  `SPORTS_DATA_RESULTS_CRON`) ; sans `--competition`, n'interroge que les compétitions qui attendent un résultat.
- En test et en développement : `FakeSportsDataProvider::setResult('fake-1', '2', '1')` publie un score.
- Chaque nouveau résultat lance `SettleEventJob` (règlement des tickets du match).
- `php artisan settlement:run [--event=ID]` : règle ou relance le règlement (rattrapage planifié toutes les
  15 minutes). Idempotent : ticket verrouillé, un règlement par révision, référence ledger
  `settlement:{ticket}:r{révision}:payout|refund|reversal` unique — une relance ne paie jamais deux fois.

## Règles de calcul (à confirmer par D-05 / D-06)

- Ticket perdu dès qu'une sélection est perdue ; gagné quand toutes sont gagnées ou annulées ; annulé
  (mise remboursée) si toutes sont annulées.
- Gain : `potential_payout_minor` figé à l'acceptation ; avec des sélections annulées (cote 1),
  mise × cotes gagnantes arrondi à l'unité mineure inférieure, plafonné au gain prévu.
- Match annulé : sélections annulées. Plus/moins sur une ligne entière atteinte exactement : annulé.
- Seules les sélections en attente sont réglées : la réévaluation après correction relève de P5-06.

## Correction de résultat (P5-06)

1. **Demande** (`ResultCorrectionService::request`, administrateur `results.enter`) : nouvelle issue + motif ; rien ne
   change. Une correction envoyée par le fournisseur **après règlement** devient aussi une demande (`requested_by`
   null) ; avant tout règlement, elle s'applique directement comme un nouveau résultat.
2. **Aperçu** : `SettlementService::reevaluate(..., apply: false)` calcule les tickets touchés sans rien écrire.
3. **Validation** par un **autre** administrateur (`results.approve`), dans une transaction : nouvelle version du
   résultat (source `manual`), puis `reevaluate(..., apply: true)` — pour chaque ticket dont l'issue change,
   écriture compensatoire du gain ou remboursement précédent (`reversal`, le solde peut devenir négatif), nouvelle
   écriture si besoin, nouvelle révision du règlement. Un combiné perdu sur ce match redevient `pending` si d'autres
   matchs ne sont pas joués ; il sera réglé normalement plus tard (révision suivante).
4. **Refus** par un administrateur `results.approve`, ou **retrait** par le demandeur.

Chaque étape est auditée avec son motif (`result.correction_requested|approved|rejected`).

## Routes

- Pas de route publique : règlement déclenché par les jobs ; corrections et historique par l'API admin
  (`/api/v1/admin/events/{id}/results`, `/api/v1/admin/events/{id}/result-corrections`,
  `/api/v1/admin/result-corrections[/{id}[/approve|/reject]]`).

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
