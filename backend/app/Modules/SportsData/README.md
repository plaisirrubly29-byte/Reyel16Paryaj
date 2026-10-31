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
- `sports_data_api_calls` (journal des appels au fournisseur)

## Routes prévues

- (aucune route publique — jobs planifiés et webhooks signés)

## Fournisseur (P3-02)

| `SPORTS_DATA_PROVIDER` | Implémentation | Usage |
|---|---|---|
| `fake` | `Providers/FakeSportsDataProvider` | Développement et tests : données déterministes, panne et cotes périmées simulables |
| `theodds` | `Providers/TheOddsApiProvider` | The Odds API v4 (clé dans `SPORTS_DATA_API_KEY`) |

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
