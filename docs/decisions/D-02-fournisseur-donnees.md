# D-02 — Fournisseur de données sportives et de cotes

| | |
|---|---|
| **Tâche** | P0-02 (débloque P3-02 fournisseur, P3-03 synchro, P5-04 résultats) |
| **Statut** | 🟡 Proposition à valider |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-29 |

## 1. Décision proposée

**The Odds API** (`https://api.the-odds-api.com/v4`) est le fournisseur unique de la V1, pour les matchs,
les cotes et les scores. Le code l'utilise déjà (`SPORTS_DATA_PROVIDER=theodds`,
`app/Modules/SportsData/Providers/TheOddsApiProvider.php`). Le faux fournisseur (`fake`) reste celui du
développement et des tests.

## 2. Critères examinés

| Critère | The Odds API | Remarque |
|---|---|---|
| Couverture | Football (ligues européennes, coupes UEFA, Amériques), NBA, NFL, MLB, NHL… | Suffit pour la liste D-03 |
| Données | Matchs, cotes de plusieurs bookmakers, scores des matchs terminés et en cours | Un seul fournisseur pour cotes **et** résultats |
| Marchés | `h2h` (1X2), `totals` (plus/moins), et d'autres selon le sport | V1 : voir D-03 |
| Sandbox | Clé gratuite (500 crédits/mois), mêmes données que l'offre payante | Clé détenue par Rubly, stockée dans `backend/.env`, **jamais dans le dépôt** |
| Coût d'un appel de cotes | marchés × régions, par compétition | `h2h` + région `eu` = 1 crédit ; scores = 2 crédits |
| Limites | Quota mensuel de crédits, remis à zéro chaque mois | Protégé par `CreditBudget` et `SPORTS_DATA_MIN_REMAINING` |
| Intégration | API REST JSON simple, aucune librairie imposée | Déjà livrée (P3-02, P3-03, P5-04) |

## 3. Quota et offre à prendre

Synchro toutes les 4 h = 180 appels par compétition et par mois en `h2h`. Avec les 10 compétitions de D-03 :
≈ 1 800 crédits de cotes + les scores (≈ 2 crédits par compétition et par heure de match) + le direct
(plafonné à 30 crédits par jour). **L'offre gratuite (500) ne suffit pas** : le budget de crédits fait alors
tourner les compétitions et certaines cotes deviennent périmées (masquées par la règle de fraîcheur).

- **Développement et Staging** : offre gratuite, le budget de crédits suffit.
- **Production** : offre payante d'au moins **20 000 crédits/mois** (tarif à vérifier sur le site du fournisseur
  le jour de la souscription), puis `SPORTS_DATA_MONTHLY_CREDITS` mis à la nouvelle valeur.

## 4. Réserves

- **Droits d'usage** : la V1 n'utilise que des fonds fictifs (D-01). Avant tout pari en argent réel (P7-01),
  obtenir par écrit l'accord du fournisseur pour un usage « paris » commercial, ou changer de fournisseur.
- **Bookmaker de référence** : non fixé en V1 (`SPORTS_DATA_REFERENCE_BOOKMAKER` vide = premier bookmaker
  disponible). Les cotes ne sont pas retouchées (pas de marge) tant que les fonds sont fictifs.

## 5. Critères d'acceptation P0-02

- [x] Fournisseur choisi et documenté (ce document)
- [x] Accès sandbox obtenu (clé stockée hors du dépôt, dans `backend/.env`)
- [x] Documentation de l'API fournisseur liée : <https://the-odds-api.com/liveapi/guides/v4/>
