# D-18 — Politique de cotes : marge de l'opérateur, arrondi et modification manuelle

| | |
|---|---|
| **Tâche** | V2-A04 (#140) — décision ; prépare V2-D03 (#167 backend, #168 écrans) |
| **Statut** | 🟡 À décider — options et recommandation proposées |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-13 |
| **Dépend de** | D-02 (fournisseur de cotes : « pas de marge tant que les fonds sont fictifs »), D-06 (arrondi, plafonds), D-07 (rôles admin), V2-B04 (argent réel, #146) |

> Les ordres de grandeur de marge viennent de sources publiques sur le secteur ; ils sont indicatifs. **La marge ne
> s'applique qu'avec l'argent réel (V2-B04)** : tant que les fonds sont fictifs, les cotes restent celles du
> fournisseur (D-02). Aucun code n'est écrit tant que D-18 n'est pas validée.

## 1. Besoin

Critères de #140 : **« Marge par sport ou marché décidée »** et **« Rôles autorisés à modifier une cote fixés »**.
Critères de #167 (code) : marge configurable, modification auditée, cote modifiée signalée dans le catalogue.

La **marge** est l'avantage de l'opérateur : chaque cote affichée est un peu plus basse que la cote « juste », ce qui
assure un bénéfice moyen sur l'ensemble des paris. Trop faible, l'opérateur perd de l'argent sur les erreurs de
cote ; trop forte, les joueurs vont voir ailleurs.

## 2. Déjà en place

- **Cotes de The Odds API**, recopiées **telles quelles** depuis un bookmaker de référence
  (`SPORTS_DATA_REFERENCE_BOOKMAKER`, vide = premier disponible). Région `eu` : un même appel renvoie les cotes de
  plusieurs bookmakers.
- Chaque changement de cote crée une **version** (`markets.odds_version`, historique dans `odds_snapshots`) ; le
  ticket fige la version acceptée.
- Cotes stockées à **3 décimales** ; gain arrondi au **centime inférieur** et plafonné (D-06).
- Paramètres de la plateforme **versionnés et audités** (Super Admin, `settings.update`).
- **Aucune modification manuelle** des cotes : l'admin peut seulement suspendre ou rouvrir un marché (Opérations,
  motif, `markets.suspend`).

**Point clé** : les cotes d'un bookmaker contiennent **déjà sa propre marge** (environ 4 à 7 % sur un 1X2 de
football). Ajouter une marge par-dessus, c'est en empiler deux.

## 3. Options

| | **A. Cotes telles quelles** | **B. Marge ajoutée** | **C. Cote juste + marge de l'opérateur** |
|---|---|---|---|
| Principe | On garde la marge du bookmaker source | Cote source × (1 − x %) | On retire la marge des bookmakers (moyenne de plusieurs), puis on applique notre marge cible |
| Marge réelle | Celle du bookmaker, variable et inconnue | ~7 à 10 % (deux marges) | **Celle que l'on choisit**, par sport et par marché |
| Attractivité | Comme le bookmaker source | Moins bonne que la concurrence | Réglable |
| Complexité | Aucune (en place) | Faible | Moyenne : calcul à chaque synchronisation |

### Méthode de l'option C (proportionnelle, la plus courante)

1. Pour chaque issue, **probabilité implicite** = 1 / cote, chez chaque bookmaker disponible ; on en fait la
   **moyenne**.
2. On **retire la marge** : chaque probabilité est divisée par leur somme, et le total vaut alors 100 %.
3. On **applique notre marge** M : cote = 1 / (probabilité × (1 + M)).
4. On **arrondit à 2 décimales vers le bas**, sans jamais descendre sous 1,01.

> Exemple, 1X2 avec des probabilités justes de 45 % / 28 % / 27 % et M = 6 % :
> cotes justes 2,222 / 3,571 / 3,704 → après marge 2,096 / 3,369 / 3,494 → arrondies **2,09 / 3,36 / 3,49**
> (marge réelle après arrondi : ~6,3 %).

Garde-fous :
- **Au moins 2 bookmakers** pour calculer la cote juste ; sinon, cote du bookmaker de référence telle quelle (elle
  contient déjà une marge).
- **Jamais au-dessus de la meilleure cote du marché** chez les bookmakers suivis (évite qu'un parieur exploite un
  écart).
- La version de la cote et ses sources sont gardées dans l'historique (`odds_snapshots`).

## 4. Modification manuelle d'une cote

| Règle | Proposition |
|---|---|
| Qui | **Opérations** et **Super Admin** (nouvelle permission `odds.override`), avec un motif écrit |
| Double validation | Au-delà de **±20 %** par rapport à la cote calculée : un second administrateur valide (comme les résultats) |
| Durée | La cote modifiée reste fixe **jusqu'à une date limite ou au début du match** ; la synchronisation ne l'écrase pas pendant ce temps |
| Effet sur les tickets | Nouvelle version de cote : les tickets déjà placés gardent la leur |
| Signalement | La cote est marquée **« ajustée »** dans le catalogue (#167) ; l'admin voit l'écart avec la cote calculée |
| Audit | Chaque modification, validation et fin de modification est journalisée (qui, quand, ancienne et nouvelle cote, motif) |
| Retour | Un administrateur peut revenir à la cote calculée à tout moment, avec motif |

## 5. Recommandation

1. **Option C**, appliquée **avec l'argent réel seulement** (V2-B04).
2. **Marges de départ**, réglables dans les paramètres (Super Admin, versionnés) :

   | Marché | Marge |
   |---|---|
   | Football 1X2 | 6 % |
   | Football plus/moins, double chance, les deux équipes marquent | 5 % |
   | Basket et baseball, vainqueur et plus/moins | 5 % |
   | Marchés secondaires (handicap, score exact, buts par équipe…) | 8 % |
   | Paris en direct (si ouverts, D-04 et V2-D06) | +2 points |

3. **Arrondi** : 2 décimales vers le bas, minimum 1,01.
4. **Modification manuelle** selon le §4.

## 6. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| CO-1 | Méthode de marge | Option C : cote juste (moyenne des bookmakers) + marge de l'opérateur, méthode proportionnelle | |
| CO-2 | Marges par sport et par marché | Tableau du §5 | |
| CO-3 | Quand appliquer la marge | Avec l'argent réel seulement (V2-B04) ; cotes du fournisseur telles quelles en fonds fictifs | |
| CO-4 | Arrondi | 2 décimales vers le bas, minimum 1,01 | |
| CO-5 | Nombre minimum de bookmakers | 2 ; sinon cote du bookmaker de référence telle quelle | |
| CO-6 | Plafond | Jamais au-dessus de la meilleure cote des bookmakers suivis | |
| CO-7 | Rôles pour modifier une cote | Opérations et Super Admin, motif obligatoire | |
| CO-8 | Seuil de double validation | Écart de plus de ±20 % avec la cote calculée | |
| CO-9 | Durée d'une cote modifiée | Jusqu'à une date limite ou au début du match | |
| CO-10 | Qui règle les marges | Super Admin (paramètres versionnés), Opérations en lecture | |
| CO-11 | Bookmaker de référence | À fixer (aujourd'hui : le premier disponible) | |

## 7. Conséquences techniques

- **Backend (Benginio, #167)** : calcul de la cote juste et de la marge à chaque synchronisation (`CatalogueSync`) ;
  conservation des cotes des bookmakers sources dans l'historique ; paramètres de marge versionnés ; table des cotes
  modifiées (cote, motif, auteur, validateur, date limite) ; nouvelle permission `odds.override` et ligne de la matrice
  (avec les tests de P6-09) ; actions d'audit ; contrat d'API d'abord dans `docs/api/openapi.yaml` (indicateur « cote
  ajustée » dans le catalogue, routes admin). Aucune nouvelle dépendance.
- **Quota du fournisseur** : aucun crédit en plus (les autres bookmakers sont déjà dans la réponse de la région `eu`).
- **Frontend (Rubly, #168)** : écran de modification d'une cote et de validation ; mention « ajustée » dans le
  catalogue ; réglage des marges dans les paramètres.
- **Tests** : calcul (exemples chiffrés du §3), arrondi, garde-fous, double validation, synchronisation qui n'écrase
  pas une cote modifiée.

## 8. Conditions pour passer à « Validée »

1. CO-1 à CO-11 tranchés par écrit.
2. Ligne « Cotes — modifier » ajoutée à la matrice des permissions (D-07).

## 9. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |

## Sources (consultées le 13 novembre 2026)

- Méthodes de marge : [LSports — méthodes de marge](https://docs.lsports.eu/lsports/products/product-overview/margin-methods-overview),
  [LSports — calcul de la marge](https://docs.lsports.eu/u/trade/concepts/margin-calculation-method),
  [LSports — la marge expliquée](https://lsports.eu/blog/how-do-bookmakers-set-odds-overround-explained),
  [Altenar — avantage de la maison](https://altenar.com/blog/house-edge-in-sports-betting-how-the-best-sportsbooks-manage-their-edge/),
  [Sports Models KB — retrait de la marge](https://sports-models-kb.pages.dev/de-vigging),
  [Compare n' Bet — méthodes](https://comparenbet.pages.dev/guide-devigging-methods),
  [Encyclopedia.pub](https://encyclopedia.pub/entry/60611)
- Mathématiques des bookmakers : [Wikipédia](https://Www.Wikipedia.org/wiki/Overround),
  [Karl Whelan — comment les bookmakers fixent les cotes](https://www.karlwhelan.com/sportsbetting/the-economics-of-how-bookmakers-set-odds/),
  [Karl Whelan — calcul de la marge](https://www.karlwhelan.com/?p=2557)
