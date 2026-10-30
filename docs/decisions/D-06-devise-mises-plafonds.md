# D-06 — Devise, arrondi, mises min/max et plafonds de gain

| | |
|---|---|
| **Tâche** | P0-05 (débloque P5-01 ledger, P4-03 prise de pari) |
| **Statut** | ✅ Validé — Rubly PLAISIR, 2026-10-29 |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-29 |

## 1. Paramètres V1

| Paramètre | Valeur | Config |
|---|---|---|
| Devise | **Gourde haïtienne (HTG)**, une seule devise | `APP_CURRENCY=HTG` |
| Fonds | **Fictifs uniquement** (aucun dépôt ni retrait réel tant que P7-01 est bloqué) | `wallet.simulated` |
| Unité de stockage | Centime (unité mineure), entier `bigint`, jamais de nombre à virgule | `App\Support\Money` |
| Crédit fictif à l'inscription | 5 000,00 HTG | `WALLET_WELCOME_CREDIT_MINOR=500000` |
| Mise minimum par ticket | **10,00 HTG** | `BETTING_MIN_STAKE_MINOR=1000` |
| Mise maximum par ticket | **50 000,00 HTG** | `BETTING_MAX_STAKE_MINOR=5000000` |
| Gain maximum par ticket | **1 000 000,00 HTG** | `BETTING_MAX_PAYOUT_MINOR=100000000` |
| Sélections par combiné | 20 au plus | `BETTING_MAX_SELECTIONS=20` (D-04) |
| Version des règles | `v1-2026-10` | `BETTING_RULES_VERSION` |

Les limites de jeu responsable choisies par le joueur (jour, semaine, mois) s'ajoutent à ces plafonds (P7-02).

## 2. Arrondi

1. **Cote totale** d'un combiné : produit des cotes calculé à 6 décimales, affiché à 3 décimales.
2. **Gain** = mise × cote totale, **arrondi au centime inférieur** (jamais en faveur du joueur par excès),
   puis plafonné au gain maximum.
3. Avec des sélections annulées (cote 1), le gain est recalculé de la même façon et ne dépasse jamais le gain
   potentiel affiché à l'acceptation.

> Exemples
> - Mise 100,00 HTG, cotes 2,10 × 1,85 × 1,50 = 5,8275 → gain **582,75 HTG**.
> - Mise 33,33 HTG, cote 1,87 → 62,3271 → gain **62,32 HTG** (centime inférieur).
> - Mise 50 000,00 HTG, cote 25,00 → 1 250 000,00 → gain plafonné à **1 000 000,00 HTG**.
> - Mise 5,00 HTG → refusée (`stake_out_of_range`, minimum 10,00).

## 3. Changer une valeur

Toute modification passe par une PR qui change la variable d'environnement **et** `BETTING_RULES_VERSION`.
Les tickets déjà acceptés gardent les règles figées dans leur `rules_snapshot`.

## 4. Critères d'acceptation P0-05

- [x] Devise(s), règle d'arrondi, min/max et plafonds consignés
