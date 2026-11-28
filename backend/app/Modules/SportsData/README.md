# Module Sports Data Adapter

**Namespace :** `App\Modules\SportsData`
**Livraison :** Phase 3
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte) — accès The Odds API

## Responsabilités

- Synchroniser sports, ligues, équipes, événements, marchés et cotes depuis le fournisseur contracté.
- Valider schéma, source, horodatage et état avant toute mise à jour.
- Détecter données périmées / panne fournisseur et déclencher la suspension.

## Dépendances

- Fournisseur de données
- Queue (Redis)
- Logs

## Tables (modèle de données initial)

- `sports`
- `competitions`
- `teams`
- `events`
- `markets`
- `selections`
- `odds_snapshots`
- `odds_overrides` (cotes fixées à la main, V2-D03)
- `sports_data_api_calls` (journal des appels au fournisseur)

## Routes prévues

- (aucune route publique — jobs planifiés et webhooks signés)

## Fournisseur (P3-02)

| `SPORTS_DATA_PROVIDER` | Implémentation | Usage |
|---|---|---|
| `fake` | `Providers/FakeSportsDataProvider` | Développement et tests : données déterministes, panne et cotes périmées simulables |
| `theodds` | `Providers/TheOddsApiProvider` | The Odds API v4 (clé dans `SPORTS_DATA_API_KEY`) |

### Football depuis SportMonks (à côté du fournisseur principal)

`Providers/SportMonksProvider` (SportMonks Football API v3), actif dès que `SPORTMONKS_API_KEY` est renseignée :
The Odds API garde les autres sports, SportMonks apporte le football de son abonnement.

- **`php artisan sports-data:sportmonks`** (`SPORTMONKS_SYNC_CRON`, 30 min) : matchs et cotes des ligues de
  l'abonnement (`SPORTMONKS_LEAGUES`, `*` = toutes), 1 appel par ligue ; `--results` (`SPORTMONKS_RESULTS_CRON`) :
  résultats des matchs commencés ; `--live` (chaque minute) : score des matchs en cours, 1 seul appel
  (`/livescores/inplay`) pour toutes les ligues, aucun sans match en cours. Mêmes services que The Odds API
  (`CatalogueSyncService`, `ResultImportService`) : matchs et compétitions portent le fournisseur `sportmonks`.
- **Clés** : celle de The Odds API quand la ligue y correspond (`league_keys` : 8 → `soccer_epl`, 82, 271, 501 ;
  ajouts par `SPORTMONKS_LEAGUE_KEYS="9=soccer_efl_champ"`) pour garder libellés, menu et marge ; sinon
  `soccer_sm_{id}`. **Pas de doublon** : `sports-data:sync` saute et retire du catalogue les compétitions que
  SportMonks a déjà (actives) ; SportMonks coupé, elles reviennent à The Odds API.
- **Marchés** : 1X2 (1), plus/moins (80, ligne 2.5 si cotée), les deux équipes marquent (14), double chance (2),
  score exact (57), transposés au format de The Odds API ; un marché par bookmaker de `SPORTMONKS_BOOKMAKERS`
  (le premier = référence, 2 = bet365), les autres pour la cote juste. Les marchés maison suivent comme d'habitude.
- **Résultats** : terminé = états `FT`, `AET`, `FT_PEN` ; après prolongation, score de fin de 2e mi-temps (`2ND_HALF`).
- **Limite** : pas de crédits mais un nombre d'appels par heure et par type de données (180 en formule gratuite) ;
  journal `sports_data_api_calls` avec coût 0 et appels restants ; un 429 donne `quota_exhausted`. Clé envoyée dans
  l'en-tête `Authorization`, jamais dans l'URL ni les journaux.

```php
$provider = app(SportsDataProvider::class);
$provider->sports();                 // gratuit
$provider->upcomingStarts('soccer_epl'); // gratuit (/events : heures de début, sans cotes)
$provider->events('soccer_epl');     // coût = marchés × régions (2 crédits avec h2h,totals + eu)
$provider->results('soccer_epl', 2); // 2 crédits (1 sans daysFrom)
```

- **Quota** : offre gratuite = 500 crédits/mois. Le quota restant est lu dans les en-têtes `x-requests-*`
  et mémorisé ; sous `SPORTS_DATA_MIN_REMAINING`, les appels payants sont refusés (`quota_exhausted`).
- **Diagnostic sans coût** : `php artisan sports-data:check` (connexion, sports configurés, quota).
- **Matchs de scénario** (tests de bout en bout, P8-01, faux fournisseur hors production) :
  `sports-data:fake-match create` ajoute un match unique, mémorisé dans le cache ; `sports-data:fake-match finish
  <id> 2-1` le fait commencer dans le passé et publie son score. Import et règlement suivent leur chemin normal
  (voir `e2e/README.md`).

## Marchés synchronisés

`SPORTS_DATA_MARKETS=h2h,totals` (`.env.example` ; `h2h` seul par défaut dans la config). Chaque marché demandé
coûte 1 crédit par région : `h2h,totals` + `eu` = **2 crédits par compétition et par synchro** (le budget en tient
compte automatiquement).

| Marché | Sélections (`key` → nom stocké) | Ligne (`markets.line`) | Règle |
|---|---|---|---|
| `h2h` (1X2) | `home`, `draw` → « Match nul », `away` | `null` | Obligatoire : invalide, l'événement est rejeté (`sports_data.event_rejected`) |
| `totals` (plus/moins) | `over` → « Plus de 2.5 », `under` → « Moins de 2.5 » | `"2.5"` (chaîne, sans zéros inutiles) | Facultatif : exactement un `Over` et un `Under` sur la même ligne ; sinon le marché est ignoré (`sports_data.market_rejected`) sans rejeter l'événement |

- Un changement de ligne (ex. 2.5 → 3.5) compte comme un changement de cote : `odds_version` + 1 et historique
  (`odds_snapshots.line` garde la ligne de chaque version), même à cotes égales.
- Le faux fournisseur publie un plus/moins 2.5 buts sur les matchs de football (pas sur la NBA).
- Catalogue : la liste `/api/v1/events` ne montre que le 1X2 ; le détail `/api/v1/events/{id}/markets` renvoie
  le 1X2 puis le plus/moins (« Nombre de buts » au football, « Total de points » ailleurs ; libellés `+2.5` / `-2.5`).

## Cotes fixées à la main (V2-D03, décision D-18)

- **Repères gardés à chaque synchronisation** (`selections`) : `computed_odds` (cote calculée : marge de l'opérateur avec
  l'argent réel, `OddsMargin`), `provider_odds` (bookmaker de référence) et `best_odds` (meilleure cote suivie, repère
  contre l'arbitrage) ; null pour un marché maison. Les mêmes informations dans `odds_snapshots` à chaque version.
- `Services/OddsOverrideService`, routes `/api/v1/admin/markets/{id}/odds-overrides` et `/api/v1/admin/odds-overrides`
  (module Admin) : Opérations et Super Admin (`odds.override`), motif obligatoire, audit (`odds.override_*`).
- Écart de 20 % au plus avec la cote calculée (`config/odds.php`) : en vigueur tout de suite ; au-delà, un second
  administrateur valide (jamais le demandeur, rôle de plus de 24 h).
- En vigueur : `selections.adjusted = true`, nouvelle version de cote (les tickets placés gardent la leur), mention
  `adjusted` au catalogue ; la synchronisation met à jour la cote calculée mais **n'écrase pas** la cote fixée. Les
  marchés maison (V2-D10), calculés depuis le 1X2 publié, suivent une cote du 1X2 fixée à la main.
- Fin : à la demande (`end`), ou à la date limite et au plus tard au début du match (`odds:expire-overrides`, chaque
  minute) ; la cote calculée revient, nouvelle version. Une seule demande en attente ou en vigueur par sélection. Une
  sélection retirée par le fournisseur emporte sa demande (l'historique reste dans l'audit).

## Crédits et budget

- **Journal** (`sports_data_api_calls`, modèle `ProviderApiCall`) : chaque réponse de The Odds API, payante ou
  gratuite, est enregistrée (point d'accès générique `/sports/{sport}/odds`, compétition, coût `x-requests-last`,
  `x-requests-remaining`, `x-requests-used`, statut HTTP, date). Jamais la clé. Un échec d'écriture est journalisé
  sans bloquer la synchro.
- **Crédits restants** (`Services/CreditBudget`) : quota des en-têtes s'il a été observé depuis la dernière remise
  à zéro, sinon `SPORTS_DATA_MONTHLY_CREDITS` − crédits du journal depuis le début de la période
  (`SPORTS_DATA_QUOTA_RESET_DAY`, ramené au dernier jour des mois courts).
- **Budget par synchro** = ⌊(restants − `SPORTS_DATA_MIN_REMAINING`) / synchros restantes⌋, les synchros restantes
  étant comptées à partir de `SPORTS_DATA_SYNC_CRON` jusqu'à la remise à zéro. Une compétition coûte marchés × régions.
- **Sélection** (`SPORTS_DATA_SPORTS`) : clés exactes, motifs (`tennis_*` : chaque tournoi de tennis a sa clé,
  `soccer_*`…) ou `*`. Un motif ne retient que les compétitions actives de la liste gratuite `/sports`, hors
  « vainqueur final ». À chaque synchro planifiée (sans `--competition`), les compétitions déjà enregistrées que la
  liste ne désigne plus sont désactivées (`is_active`) : elles quittent le catalogue, le direct et les marchés
  supplémentaires ; leurs résultats restent importés tant qu'un pari les attend. Remises dans la liste, elles reviennent.
- **`sports-data:sync`** (si `SPORTS_DATA_BUDGET=true`) :
  1. saute les compétitions hors saison ou inconnues d'après la liste gratuite `/sports` (cache 6 h ; si la liste
     est indisponible, rien n'est sauté pour ce motif) ;
  2. saute les compétitions sans match dans l'horizon (`SPORTS_DATA_HORIZON_DAYS`) d'après la liste gratuite
     `/sports/{sport}/events` (motif `aucun match à venir` ; si la liste est indisponible, la compétition est gardée) ;
  3. synchronise d'abord les compétitions synchronisées le moins récemment (puis ordre de `SPORTS_DATA_SPORTS`),
     dans la limite du budget ; les autres sont reportées (motif `budget`, avertissement `sports_data.budget_skip`).
  `--competition=…` reste soumis au budget ; `--force` l'ignore (le seuil `SPORTS_DATA_MIN_REMAINING` reste appliqué).
- **`php artisan sports-data:credits [--json]`** (aucun appel au fournisseur) : restants/utilisés, crédits mensuels,
  réserve, période, prochaine remise à zéro, synchros restantes, coût d'une synchro complète, budget par synchro,
  projection (« suffisant » ou « insuffisant : X compétitions par synchro au lieu de Y ») et consommation de la
  période par compétition et point d'accès.
- **Erreurs** : `SportsDataException::$reason` = `unavailable`, `unauthorized`, `quota_exhausted`, `not_found`,
  `invalid_response` — base de la suspension automatique des marchés (P3-04).
- **Cotes** en chaîne décimale exacte (`"2.05"`), jamais en float.
- Les tests forcent `SPORTS_DATA_PROVIDER=fake` et une clé vide (`phpunit.xml`) : aucun test ne consomme le quota.

## Règles à respecter

- Le fournisseur est accédé via une interface (`Contracts/SportsDataProvider`) pour pouvoir utiliser un faux fournisseur en dev/test.
- Secrets fournisseur uniquement dans `.env` côté serveur.
- Synchronisation idempotente et relançable.

## Organisation

```
SportsData/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
├── Contracts
├── Jobs
├── Providers
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_sports_table`).
Tests : `tests/Feature/Modules/SportsData` et `tests/Unit/Modules/SportsData`.
