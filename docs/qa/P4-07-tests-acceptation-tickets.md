# Tests d'acceptation des tickets — P4-07

| | |
|---|---|
| **Tâche** | P4-07 — Tests d'acceptation tickets (ticket #33) |
| **Référence** | Document d'architecture, §11 « Tests obligatoires et critères d'acceptation » |
| **Auteur** | Benginio Jean-Baptiste (@benginio) |
| **Date** | 28 octobre 2026 · `develop` @ `a267f6d` |
| **Suite** | `backend/tests/Feature/Acceptance/TicketAcceptanceTest.php` (16 tests) |

## Méthode

Chaque test suit le parcours réel par l'API, sans appeler directement les services de pari :
synchronisation du faux fournisseur → `POST /bets` → import du résultat → règlement → `GET /wallet`, `GET /bets`.
Le solde est toujours lu par l'API (calculé par le serveur à partir du ledger) et les écritures du ledger sont
vérifiées. Les tests tournent à une date fixe (`2026-10-20 10:00`) : ils sont reproductibles.

```bash
cd backend && php artisan test --filter=TicketAcceptanceTest
```

## Correspondance critères ↔ tests

Les critères §11 qui ne concernent pas les tickets relèvent d'autres tâches : parcours E2E complet (P8-01),
sauvegarde et restauration (P8-04). Les tests unitaires et fonctionnels existants restent la couverture détaillée ;
la suite d'acceptation vérifie chaque critère de bout en bout.

| # | Critère §11 | Tests d'acceptation (P4-07) | Couverture existante |
|---|---|---|---|
| 1 | Un marché fermé ou une cote périmée ne peut pas être accepté | `c1_a_closed_market_is_never_accepted` (marché réglé après le résultat), `c1_a_stale_odds_is_never_accepted`, `c1_a_match_that_has_started_is_never_accepted` (cote fraîche, match commencé) | `PlaceBetTest` : `a_closed_started_or_stale_selection_is_unavailable`, `stale_odds_cannot_be_bet`, `an_unknown_selection_is_unavailable` |
| 2 | Même demande + même clé d'idempotence → pas de second ticket | `c2_the_same_request_with_the_same_key_creates_one_ticket_and_one_stake` (une mise, un ticket, solde débité une fois), `c2_the_same_key_reused_for_another_bet_is_refused` | `PlaceBetTest` : `a_replayed_request_never_bets_twice`, `a_replay_after_a_crash_returns_the_same_ticket_without_a_second_stake` (M4), `the_same_key_is_unique_per_player_in_the_database` |
| 3 | Une erreur du fournisseur ne transforme pas un pari refusé en pari accepté | `c3_a_provider_outage_never_refreshes_odds_so_bets_on_stale_odds_stay_refused` (synchro en panne : rien n'est écrit, la cote vieillit, le pari reste refusé, même rejoué avec la même clé), `c3_a_provider_outage_on_results_never_settles_or_pays` (puis règlement unique au retour du fournisseur), `c3_a_new_odds_from_the_provider_refuses_the_bet_until_the_player_confirms` (409 `odds_changed`) | `ResultImportTest` : `a_provider_failure_records_nothing` ; `PlaceBetTest` : `a_changed_odds_is_refused_with_the_new_one_and_nothing_is_created` |
| 4 | Solde et gains proviennent du ledger serveur, pas du frontend | `c4_values_sent_by_the_frontend_never_set_the_odds_the_payout_or_the_balance` (cote, gain et solde envoyés ignorés), `c4_the_payout_and_the_balance_come_from_the_ledger` (gain = écriture du ledger, solde = somme du ledger) | `PlaceBetTest` : `the_client_odds_is_never_the_authority` ; `SettlementServiceTest` : `a_winning_single_is_paid_once_with_the_accepted_odds` |
| 5 | Un même résultat traité plusieurs fois → aucun double crédit | `c5_a_result_processed_several_times_pays_once` (import répété + `settlement:run` deux fois), `c5_a_corrected_result_after_payment_never_pays_again_and_is_traced` | `SettlementServiceTest` : `replaying_the_settlement_never_pays_twice`, `sweep_settles_tickets_left_pending_by_an_interrupted_run` ; `ResultImportTest` : `reimporting_the_same_result_changes_nothing` |
| 6 | Permissions au niveau API, y compris l'accès direct par identifiant | `c6_a_ticket_is_only_reachable_by_its_owner` (invité 401, autre joueur 404, identifiant voisin 404), `c6_a_ticket_cannot_be_changed_through_the_api` (PUT, PATCH, DELETE → 405) | `BetsApiTest` : `a_player_never_sees_another_players_tickets`, `a_suspended_player_cannot_read_tickets` ; `PlaceBetTest` : `only_an_active_signed_in_player_can_bet` ; routes admin : P2-07, P6-09 |
| 7 | Changements de cotes et de résultats traçables | `c7_every_odds_change_is_versioned_and_the_ticket_keeps_the_accepted_one` (versions et historique des cotes, cote acceptée figée sur le ticket), `c7_the_stake_and_the_payout_are_ledger_entries_tied_to_the_ticket` ; correction de résultat : `c5_a_corrected_result_after_payment_never_pays_again_and_is_traced` | `ResultImportTest` : `a_different_score_is_a_new_version_with_its_history` ; `BetsApiTest` : `the_receipt_shows_the_accepted_odds_not_the_current_ones` ; suspension de marché auditée : P6-02 (PR #103) |

## Vérification des tests

Une mutation volontaire du code (accepter un match commencé) est détectée par `c1_a_match_that_has_started_is_never_accepted`.
Le double paiement est protégé par plusieurs couches indépendantes (sélections en attente seulement, mise à jour
conditionnelle, verrou sur le ticket, référence unique du ledger) : en retirer une seule ne change pas le résultat,
ce que vérifient les tests du critère 5.

## Limites connues

- **Paris en direct (E1 de la revue P8-02) :** `POST /bets` accepte un match en cours. Le périmètre V1 les exclut ;
  aucun test d'acceptation ne les couvre tant que la décision n'est pas prise (`betting.allow_live`).
- **Correction d'un résultat déjà payé :** la nouvelle version est tracée et rien n'est payé deux fois, mais le paiement
  initial n'est pas annulé automatiquement. La correction financière relève d'un processus audité à double validation
  (P6-04, PR #100).
- **Fournisseur réel :** les tests utilisent le faux fournisseur. Le comportement face à The Odds API (sandbox) sera
  vérifié en recette (§10, phase 8).
