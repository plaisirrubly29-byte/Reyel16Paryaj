<?php

/*
| Module Betting — règles d'acceptation d'un pari (P4-03).
| VALEURS PROVISOIRES en attendant les décisions D-04 (types de paris), D-05 (changement de cote)
| et D-06 (mises min/max, plafond de gain). Chaque ticket fige la version et les règles appliquées
| (rules_version, rules_snapshot) : les modifier ne change jamais un ticket déjà accepté.
| Montants en unité mineure (centimes de la devise du portefeuille).
*/

return [

    // À changer à chaque modification d'une règle ci-dessous.
    'rules_version' => env('BETTING_RULES_VERSION', 'provisoire-2026-10'),

    // Mise par ticket : 10,00 à 50 000,00.
    'min_stake_minor' => (int) env('BETTING_MIN_STAKE_MINOR', 10_00),
    'max_stake_minor' => (int) env('BETTING_MAX_STAKE_MINOR', 50_000_00),

    // Gain potentiel plafonné (le gain affiché et versé ne dépasse jamais ce montant) : 1 000 000,00.
    'max_payout_minor' => (int) env('BETTING_MAX_PAYOUT_MINOR', 1_000_000_00),

    // D-04 : combinés autorisés, 20 sélections au plus, une seule sélection par match (issues liées).
    'allow_multiple' => (bool) env('BETTING_ALLOW_MULTIPLE', true),
    'max_selections' => (int) env('BETTING_MAX_SELECTIONS', 20),

    // D-05 : "reject" — toute cote différente de celle vue par le joueur est refusée (409 odds_changed,
    // avec la nouvelle cote) ; le joueur confirme en renvoyant le pari avec la cote à jour.
    'odds_change_policy' => 'reject',

];
