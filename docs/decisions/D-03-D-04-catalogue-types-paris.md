# D-03 / D-04 — Sports, compétitions, marchés et types de paris de la V1

| | |
|---|---|
| **Tâche** | P0-03 |
| **Statut** | ✅ Validé — Rubly PLAISIR, 2026-10-29 |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-29 |

## 1. D-03 — Sports et compétitions

Liste fermée de la V1 (10 compétitions), réglée par `SPORTS_DATA_SPORTS` :

| Sport | Compétitions (clé du fournisseur) |
|---|---|
| Football | Ligue des champions (`soccer_uefa_champs_league`), Ligue Europa (`soccer_uefa_europa_league`), Ligue Europa Conférence (`soccer_uefa_europa_conference_league`), Premier League (`soccer_epl`), LaLiga (`soccer_spain_la_liga`), Serie A (`soccer_italy_serie_a`), Bundesliga (`soccer_germany_bundesliga`), Ligue 1 (`soccer_france_ligue_one`), MLS (`soccer_usa_mls`) |
| Basketball | NBA (`basketball_nba`) |

Les autres compétitions présentes dans `config/sports_data.php` (Libertadores, NFL, NHL…) ont déjà un nom
affiché, mais ne sont **pas ouvertes** en V1. En ouvrir une = nouvelle décision + vérification du quota (D-02).

Valeur de production :

```
SPORTS_DATA_SPORTS=soccer_uefa_champs_league,soccer_uefa_europa_league,soccer_uefa_europa_conference_league,soccer_epl,soccer_spain_la_liga,soccer_italy_serie_a,soccer_germany_bundesliga,soccer_france_ligue_one,soccer_usa_mls,basketball_nba
```

## 2. D-03 — Marchés

| Marché | Clé | V1 | Règlement |
|---|---|---|---|
| Résultat du match (1X2) | `h2h` | ✅ Ouvert | Score final publié par le fournisseur. Football : temps réglementaire (90 min + arrêts de jeu), sans prolongation ni tirs au but — **à vérifier sur un match de coupe UEFA à prolongation** avant la mise en production. NBA : prolongations comprises (pas de nul) |
| Plus/moins de buts ou de points | `totals` | ✅ Ouvert si le quota le permet (`SPORTS_DATA_MARKETS=h2h,totals`, coût × 2) | Total égal à une ligne entière : sélection annulée (cote 1) |
| Double chance | `double_chance` | Pris en charge par le code, ouvert seulement si le fournisseur le publie | 2 issues sur 3 |
| Les deux équipes marquent | `btts` | Pris en charge par le code, ouvert seulement si le fournisseur le publie | Oui / Non |

**Prolongations dans le score de The Odds API — vérification du 2026-11-22 (audit B3) : non tranchée.**
- Documentation v4 (guide `/scores` et page des marchés) : rien ne dit si le score `completed` d'un match de football
  inclut la prolongation ou les tirs au but. Seul le score exact est décrit « at Regulation Time » ; le 1X2 compte le nul
  et la qualification est un marché à part (`to_qualify`), ce qui laisse penser au temps réglementaire sans le prouver.
- Résultat réel déjà importé : pas encore regardé (accès au serveur non obtenu pendant cette vérification).
- Code actuel : `ResultImportService` enregistre le score de The Odds tel quel ; la règle « 90 min » n'est imposée qu'à
  la saisie manuelle. Rien n'est changé tant que la question n'est pas tranchée.
- À faire : comparer, dans la table `results` du Staging ou de la Production, le score d'un match de coupe allé en
  prolongation (FA Cup, Coupe de France, Coupe du Roi, phases finales UEFA) au score officiel à 90 min. Si la
  prolongation est incluse, Rubly décide de la correction avant tout code.

Paris en direct : **hors V1** (`BETTING_ALLOW_LIVE=false`). Un match commencé reste affiché avec son score,
mais n'est plus pariable.

> **Remplacé depuis la V2 par [D-20](D-20-paris-en-direct.md) §10** : paris en direct ouverts au football (1X2,
> plus/moins, score exact final et marchés maison R16), paris simples seulement, délai d'acceptation de 8 s. Les
> marchés maison R16 (`r16_*`, V2-D10) s'ajoutent aussi au tableau ci-dessus. Le texte de la V1 est gardé pour
> l'historique.

## 3. D-04 — Types de paris

| Règle | Valeur V1 | Config |
|---|---|---|
| Pari simple | ✅ | — |
| Pari combiné | ✅ | `BETTING_ALLOW_MULTIPLE=true` |
| Sélections par combiné | 20 au plus | `BETTING_MAX_SELECTIONS=20` |
| Plusieurs sélections du même match | ❌ Interdit (issues liées) | contrôle serveur |
| Système, pari à handicap, cash-out | ❌ Hors V1 | — |
| Pari en direct | ❌ Hors V1 ; ✅ depuis la V2, **simple seulement** ([D-20](D-20-paris-en-direct.md) §10) | `BETTING_LIVE_ALLOW_MULTIPLE=false` |

Exemple : combiné de 3 sélections (Arsenal 2,10 · Real Madrid 1,85 · Inter 1,50), mise 100 HTG
→ cote totale 5,828 → gain potentiel **582,75 HTG**.

## 4. Critères d'acceptation P0-03

- [x] Liste fermée des sports et marchés V1
- [x] Décision simple/combiné consignée
