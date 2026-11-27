# D-21 — Cash-out : encaisser un ticket avant la fin des matchs

| | |
|---|---|
| **Tâche** | V2-D07 (#175 backend, #176 écrans) |
| **Statut** | 🟡 Proposition — à valider par Rubly PLAISIR (§9) ; **aucun code avant la validation** |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-27 par Benginio |
| **Dépend de** | D-05 (annulation, correction de résultat), D-06 (plafonds), D-18 (marge), D-20 (paris en direct : cotes fraîches, délai d'acceptation, suspension sur événement), V2-E06 (limites de pertes) |
| **Dit au joueur** | Page Règlement (`/reglement`, `BettingRulesPage.tsx`) : aujourd'hui « Le cash-out et les paris système ne sont pas proposés pour le moment » ; à mettre à jour à l'ouverture |

## 1. Besoin

Critères de #175 : **montant calculé côté serveur**, **cote revérifiée**, **ledger cohérent**.
Le joueur peut **encaisser son ticket avant la fin des matchs**, pour un montant proposé par le site : moins que le gain
possible si tout se passe bien, mais plus que rien si le ticket finit perdant.

D-20 §4.1 l'avait exclu du direct (« Pas de cash-out, décision séparée ») : c'est cette décision.

## 2. Déjà en place

- **Cotes à jour** pour chaque sélection : avant-match (synchro The Odds API, marge D-18) et en direct (D-20 §10 :
  modèle + The Odds, marge du direct + 2 points, suspension 10 s après un but). **Le direct du football est ouvert en
  Production** (D-20) : le cash-out servira surtout pendant les matchs.
- **Délai d'acceptation** du direct (D-20 §4.2) : ticket « en attente », tâche en file qui revérifie après 8 s,
  refus si la cote, le score ou le marché a changé. Le même circuit sert au cash-out (§4).
- **Fraîcheur des cotes** : cote avant-match périmée après `SPORTS_DATA_MAX_ODDS_AGE` (300 min), cote en direct après
  `SPORTS_DATA_MAX_LIVE_ODDS_AGE` (20 min) ; une cote périmée n'est pas pariable.
- **Ledger en ajout seul**, une référence unique par écriture : un cash-out ne peut pas être payé deux fois.

Manque : un statut « encaissé » du ticket, le calcul, le circuit de demande, l'écriture au ledger, et la prise en
compte par le règlement, les corrections, les limites de pertes et l'exposition (§5).

## 3. Le montant proposé

### 3.1 Formule

Pour chaque sélection **encore en attente**, on compare la cote **prise** par le joueur et la cote **actuelle** du
site :

```
valeur juste = mise × produit des cotes prises des sélections gagnées
                    × produit, pour chaque sélection en attente, de (cote prise ÷ cote actuelle)
montant proposé = valeur juste × (1 − marge du cash-out), arrondi au centime inférieur
```

- Une sélection **gagnée** compte pour sa cote prise ; une sélection **annulée** (D-05) compte pour 1.
- Une sélection **perdue** : plus rien à encaisser, le cash-out n'est pas proposé.
- La cote actuelle est celle **affichée** sur le site : elle contient déjà la marge D-18. La marge du cash-out
  (§3.3) s'y ajoute.
- Le montant ne dépasse jamais le gain possible du ticket, ni `BETTING_MAX_PAYOUT_MINOR`.

### 3.2 Exemples (marge du cash-out à 5 %)

> **Simple, l'équipe du joueur mène.** Mise 100 HTG à 2,50 (gain possible 250 HTG). Cote actuelle 1,60.
> Valeur juste = 100 × 2,50 ÷ 1,60 = 156,25 → montant proposé **148,43 HTG**.

> **Simple, l'équipe du joueur est menée.** Même ticket, cote actuelle 4,00.
> Valeur juste = 100 × 2,50 ÷ 4,00 = 62,50 → montant proposé **59,37 HTG** (le joueur limite sa perte).

> **Combiné de 3 matchs.** Mise 100 HTG à 2,00 × 1,80 × 1,50 = 5,40 (gain possible 540 HTG).
> Match 1 gagné ; match 2 en cours, cote actuelle 1,30 ; match 3 pas commencé, cote inchangée 1,50.
> Valeur juste = 100 × 2,00 × (1,80 ÷ 1,30) × (1,50 ÷ 1,50) = 276,92 → montant proposé **263,07 HTG**.

### 3.3 Marge du cash-out

| Option | Effet | Remarque |
|---|---|---|
| A. 0 % | La valeur juste, marge D-18 seulement | Généreux ; le site perd l'avantage sur les joueurs qui encaissent au bon moment |
| **B. 5 %** | Montant ≈ 95 % de la valeur juste | ✅ **proposée** : ordre de grandeur courant chez les opérateurs, simple à expliquer |
| C. 10 % | Montant ≈ 90 % de la valeur juste | Plus protecteur, mais le joueur le ressent comme une mauvaise offre |

Réglable sans déploiement (`BETTING_CASHOUT_MARGIN`), comme les marges de D-18.

## 4. Circuit d'une demande

1. **Offre** : la page du ticket demande le montant proposé (requête légère, relue toutes les quelques secondes
   pendant un match). Pas d'offre si le ticket n'est pas éligible (§5.1) : le bouton n'apparaît pas.
2. **Demande** : le joueur confirme le montant affiché. La requête porte ce montant et une clé d'idempotence.
3. **Contrôle immédiat** : éligibilité, et montant recalculé. S'il est **plus bas** que le montant confirmé : refus
   `cashout_value_changed`, avec la nouvelle offre.
4. **Délai** (si au moins une sélection est en direct) : le ticket passe « encaissement en attente » ; la tâche de
   D-20 revérifie après `BETTING_LIVE_ACCEPTANCE_DELAY` (8 s). Avant-match : pas de délai.
5. **Revérification** : même calcul. Toujours éligible et montant **au moins égal** au montant confirmé → encaissé
   **au montant confirmé** (jamais plus, jamais moins). Sinon → refus, le ticket reste en jeu comme avant.
6. **Encaissement** : écriture au ledger, ticket « encaissé », définitif.

Pourquoi un délai en direct : sans lui, un joueur qui voit un but avant que le site ne reçoive le score encaisse au
prix d'avant le but. C'est le même risque que celui du pari en direct (D-20 §3), avec la même parade.

## 5. Règles et effets sur le reste du site

### 5.1 Tickets éligibles

- Ticket **accepté et en attente** (`pending`) : ni « en attente d'acceptation », ni réglé, ni déjà encaissé.
- Paris sportifs seulement (pas la borlette).
- **Chaque sélection en attente** a une cote actuelle **fraîche**, sur un marché **ouvert** (non suspendu, non
  fermé). Un marché suspendu après un but (D-20 §10.2) suspend aussi le cash-out pendant 10 s.
- Marchés **sans cote en direct** (marchés statistiques : corners, cartons…) : le ticket n'est pas encaissable tant
  qu'une sélection de ce type est en attente.
- Montant proposé d'au moins `BETTING_MIN_STAKE_MINOR` (10,00 HTG).

### 5.2 Ledger

- **Nouveau type d'écriture `cash_out`** (crédit), référence unique `settlement:{ticket}:cashout`. Un type à part
  plutôt qu'un « gain » : les rapports, la marge (GGR) et le rapprochement distinguent ce que le joueur a encaissé de
  ce qu'il a gagné au règlement.
- Le ticket passe au statut **`cashed_out`**, avec le montant payé et la date ; un règlement de révision suivante est
  enregistré (trace complète, comme une correction).

### 5.3 Règlement et corrections

- **Le cash-out est définitif.** Le règlement ignore un ticket encaissé ; une correction de résultat (P5-06) ne le
  rouvre pas et ne reprend rien.
- **Match annulé ou reporté après un cash-out** (D-05) : le cash-out reste acquis. C'est la pratique courante, et
  l'inverse obligerait à reprendre de l'argent déjà encaissé. **À ajouter à D-05.**

### 5.4 Jeu responsable, exposition, notifications

- **Limites de pertes (V2-E06)** : le montant encaissé compte comme un gain (il réduit la perte nette). Sans ce
  branchement, un joueur qui encaisse verrait sa perte surestimée.
- **Exposition** (`ExposureService`, `ExposureGuard`) : un ticket encaissé n'y compte plus.
- **Notification** (V2-E04) : aucune, c'est le joueur qui agit ; le résultat s'affiche à l'écran.

### 5.5 Coupe-circuit et limites

- `BETTING_CASHOUT_ENABLED` : **désactivé par défaut**, ouvert seulement après les tests (§8).
- Encaissement **total** seulement au lancement (§6).
- Pas de plafond de montant au-delà de `BETTING_MAX_PAYOUT_MINOR` : le montant est toujours inférieur au gain
  possible.

## 6. Options écartées pour le lancement

| Option | Pourquoi pas maintenant |
|---|---|
| Cash-out **partiel** (encaisser une partie, laisser le reste en jeu) | Double le nombre de cas (ticket à moitié réglé) ; à voir après les premiers chiffres |
| Cash-out **automatique** (encaisser seul à un montant choisi) | Demande une tâche qui surveille chaque ticket en continu, et plus de crédits de cotes |
| Cash-out sur la **borlette** | Pas de cote qui évolue avant le tirage |

## 7. Contrat d'API (à fixer dans `openapi.yaml` après la décision)

- `GET /bets/{id}/cash-out` : offre (montant proposé, éligible ou motif, valable jusqu'à).
- `POST /bets/{id}/cash-out` : demande, avec le montant confirmé, `Idempotency-Key` ; réponse : encaissé, ou en
  attente (direct), ou refus `cashout_value_changed` / `cashout_unavailable` avec la nouvelle offre.
- `GET /bets/{id}` : le ticket indique `cashed_out` et le montant encaissé.

## 8. Conditions d'ouverture (`BETTING_CASHOUT_ENABLED=true`)

1. D-21 validée (§9), et D-05 complétée (§5.3).
2. Tests : formule (simple, combiné, sélection gagnée ou annulée), refus quand le montant baisse, délai en direct, but
   pendant le délai, double demande, ticket réglé pendant le délai, correction après cash-out, limites de pertes.
3. Écran #176 (Rubly) et page Règlement mis à jour.
4. Essai en Staging sur un vrai match en direct.

## 9. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| CO-1 | Formule | Cote prise ÷ cote actuelle, pour chaque sélection en attente (§3.1) | |
| CO-2 | Marge du cash-out | 5 %, réglable (§3.3) | |
| CO-3 | Tickets éligibles | Paris sportifs en attente, toutes les sélections en attente sur un marché ouvert à cote fraîche, hors marchés statistiques (§5.1) | |
| CO-4 | Délai en direct | Même délai que le pari en direct (8 s), revérification ; pas de délai avant-match (§4) | |
| CO-5 | Montant qui change pendant la demande | Payé au montant confirmé s'il reste au moins égal ; sinon refus avec la nouvelle offre (§4) | |
| CO-6 | Encaissement partiel | Non au lancement (§6) | |
| CO-7 | Match annulé ou résultat corrigé après un cash-out | Cash-out définitif ; ajouté à D-05 (§5.3) | |
| CO-8 | Écriture au ledger | Nouveau type `cash_out` (§5.2) | |
| CO-9 | Ouverture | Désactivé par défaut ; ouvert après les conditions du §8 | |
