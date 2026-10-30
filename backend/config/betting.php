<?php

/*
| Module Betting — règles d'acceptation d'un pari (P4-03).
| Valeurs validées le 2026-10-29 : décisions D-04 (types de paris), D-05 (changement de cote, report)
| et D-06 (mises min/max, plafond de gain), voir docs/decisions/. Chaque ticket fige la version et les règles appliquées
| (rules_version, rules_snapshot) : les modifier ne change jamais un ticket déjà accepté.
| Montants en unité mineure (centimes de la devise du portefeuille).
*/

return [

    // À changer à chaque modification d'une règle ci-dessous.
    'rules_version' => env('BETTING_RULES_VERSION', 'v1-2026-10'),

    // Mise par ticket : 10,00 à 50 000,00.
    'min_stake_minor' => (int) env('BETTING_MIN_STAKE_MINOR', 10_00),
    'max_stake_minor' => (int) env('BETTING_MAX_STAKE_MINOR', 50_000_00),

    // Gain potentiel plafonné (le gain affiché et versé ne dépasse jamais ce montant) : 1 000 000,00.
    'max_payout_minor' => (int) env('BETTING_MAX_PAYOUT_MINOR', 1_000_000_00),

    // D-04 : combinés autorisés, 20 sélections au plus, une seule sélection par match (issues liées).
    'allow_multiple' => (bool) env('BETTING_ALLOW_MULTIPLE', true),
    'max_selections' => (int) env('BETTING_MAX_SELECTIONS', 20),

    // Paris sur les matchs en cours : HORS PÉRIMÈTRE V1 (docs/architecture.md, revue P8-02 E1). Un match en direct
    // reste affiché avec son score, mais ses marchés ne sont pas pariables (selection_unavailable). Ne l'activer
    // qu'après une décision écrite, avec des cotes rafraîchies en quelques secondes et un délai d'acceptation.
    'allow_live' => (bool) env('BETTING_ALLOW_LIVE', false),

    // D-05 : "reject" — toute cote différente de celle vue par le joueur est refusée (409 odds_changed,
    // avec la nouvelle cote) ; le joueur confirme en renvoyant le pari avec la cote à jour.
    'odds_change_policy' => 'reject',

    // D-05 : match reporté — la sélection est annulée (cote 1) si le match ne commence pas dans ce délai
    // après l'heure prévue au moment du pari (bet_selections.event_starts_at).
    'postponed_void_hours' => (int) env('BETTING_POSTPONED_VOID_HOURS', 48),

];
