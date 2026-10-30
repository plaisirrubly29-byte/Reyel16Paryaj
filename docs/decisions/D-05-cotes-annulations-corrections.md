# D-05 — Changement de cote, annulation, report, abandon et correction de résultat

| | |
|---|---|
| **Tâche** | P0-04 (débloque P4-03 prise de pari, P5-05 règlement, P5-06 correction) |
| **Statut** | 🟡 Proposition à valider |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-29 |

Chaque ticket fige la cote, l'heure du match et la version des règles au moment du pari (`rules_version`,
`rules_snapshot`) : changer une règle ne modifie jamais un ticket déjà accepté.

## 1. Changement de cote pendant la prise de pari

**Règle : refus et nouvelle confirmation** (`odds_change_policy = reject`). Seule la cote du serveur fait foi.
Si elle diffère de celle vue par le joueur, **à la hausse comme à la baisse**, le pari est refusé
(`409 odds_changed`) avec la nouvelle cote ; le joueur confirme en renvoyant le pari.

> Exemple : le joueur voit Arsenal à **1,85** et mise 100 HTG. Entre-temps, la synchro passe la cote à **1,80**.
> Réponse 409 avec 1,80. S'il confirme, le ticket est accepté à 1,80 (gain potentiel 180,00 HTG au lieu de 185,00).

Une cote périmée (plus vieille que `SPORTS_DATA_MAX_ODDS_AGE` = 300 min) ou un marché suspendu n'est jamais
proposé ni accepté.

## 2. Match annulé ou abandonné sans résultat

**Règle : sélection annulée, comptée à la cote 1.** Une sélection simple est remboursée (mise rendue) ;
dans un combiné, les autres sélections continuent.

> Exemple : combiné 3 sélections à 2,10 · 1,85 · 1,50, mise 100 HTG (gain potentiel 582,75 HTG).
> Le 2ᵉ match est annulé → cote retenue 2,10 × 1 × 1,50 = 3,15 → si les deux autres sont gagnées,
> gain **315,00 HTG**. Si les 3 matchs sont annulés : ticket annulé, **100 HTG remboursés**.

Un match abandonné **avec** un score officiel publié par le fournisseur est réglé sur ce score.

## 3. Match reporté

**Règle : si le match n'a pas commencé dans les 48 h suivant l'heure prévue au moment du pari, la sélection
est annulée (cote 1).** S'il est joué dans les 48 h, le pari reste valable et se règle normalement.
L'heure de référence est `bet_selections.event_starts_at` (figée à l'acceptation), pas la nouvelle heure
envoyée par le fournisseur.

> Exemple : pari pris sur un match prévu samedi 20:00. Le fournisseur le décale à dimanche 15:00 (+19 h) :
> pari valable. Il le décale au mercredi suivant (+4 jours) : sélection annulée lundi 20:00, mise remboursée
> (simple) ou cote 1 (combiné).

Un match joué à l'heure dont le score n'arrive pas (panne fournisseur) **n'est pas** annulé : il attend le
résultat ou une saisie manuelle par un administrateur (`results.enter`).

> ⚠️ **Pas encore dans le code** : un match reporté laisse aujourd'hui ses tickets en attente. À livrer avant la
> production (tâche de suivi, module Settlement).

## 4. Plus/moins sur une ligne entière

Total exactement égal à la ligne (ex. ligne 3, score 2-1) : sélection annulée (cote 1). Avec une ligne en
demi-point (2,5) ce cas n'existe pas.

## 5. Correction de résultat

| Moment | Règle |
|---|---|
| Avant tout règlement du match | Le nouveau résultat remplace l'ancien directement (nouvelle version, l'ancienne est conservée). |
| Après règlement | Demande de correction **à double contrôle** : un administrateur `results.enter` la demande avec un motif, un **autre** administrateur `results.approve` la valide. Rien ne change avant la validation. |
| Effet de la validation | Pour chaque ticket dont l'issue change : écriture **compensatoire** du gain ou du remboursement versé (le solde peut devenir négatif), puis nouvelle écriture si besoin. Aucune écriture n'est modifiée ni supprimée. |
| Correction envoyée par le fournisseur après règlement | Devient une demande à valider, comme ci-dessus. |
| Délai | Une correction est acceptée **jusqu'à 72 h** après le premier règlement du match ; au-delà, le résultat est définitif (sauf décision écrite de la Conformité). |

> Exemple : ticket simple Arsenal gagnant 1,85, mise 100 HTG, payé 185,00 HTG sur un score 2-1.
> Le score est corrigé en 1-1 et la correction validée : écriture de −185,00 HTG (annulation du gain).
> Le ticket passe « perdu ». Si le joueur avait déjà dépensé ses gains, son solde devient négatif.

> ⚠️ **Délai de 72 h pas encore dans le code** (la demande de correction est acceptée sans limite de temps).

## 6. Critères d'acceptation P0-04

- [x] Règles écrites avec exemples chiffrés
- [ ] Validées par le propriétaire du projet
