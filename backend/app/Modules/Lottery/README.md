# Module Lottery (borlette)

**Namespace :** `App\Modules\Lottery`
**Responsable :** Rubly PLAISIR (@plaisirrubly29-byte)

## Périmètre

Le fournisseur externe donne **seulement les tirages et leurs résultats publiés**. Tout le reste (vente des tickets,
paiement des gains, plafonds par boule, jeu responsable) est géré dans la plateforme : étape 2, pas encore codée.

| Étape | Contenu | État |
|---|---|---|
| 1 | Tirages, résultats, synchro, routes publiques en lecture | Fait (faux fournisseur et API Borlette UnitySoftConnect) |
| 2 | Tickets de borlette (boule, mariage, loto 3/4/5), ledger, plafonds par boule, règlement | À faire |

La borlette figure dans les exclusions par défaut de `docs/architecture.md` : tout est coupé tant que
`LOTTERY_ENABLED=false` (routes en 404, synchro non planifiée).

## Tables

- `lottery_draws` : un tirage (`external_key` du fournisseur, nom, heure locale `HH:MM`, fuseau, jours ISO ou null =
  tous les jours, actif ou non).
- `lottery_results` : un résultat par tirage et par date locale (unique) : `lot1`, `lot2`, `lot3` (deux chiffres,
  chaînes), `pick3` / `pick4` bruts si le fournisseur les donne, source, `published_at`.

## Fournisseur

| `LOTTERY_PROVIDER` | Implémentation | Usage |
|---|---|---|
| `fake` | `Providers/FakeLotteryProvider` | Développement et tests : 4 tirages (Floride et New York, midi et soir), numéros déterministes, panne et résultats imposés simulables. **Refusé en production** par `lottery:sync`. |
| `unitysoftconnect` | `Providers/UnitySoftConnectLotteryProvider` | [API Borlette UnitySoftConnect](https://unityscgroup.com/pages/api-bolet.php) (`LOTTERY_API_URL`, clé Bearer dans `LOTTERY_API_KEY`) |

L'adaptateur traduit la réponse du fournisseur en trois lots. Si le fournisseur ne donne que les numéros bruts, la règle
de calcul vit dans l'adaptateur.

### API Borlette UnitySoftConnect

- `GET /draws` → tirages : clé = `code` (ex. `NYM`), heure = `close_time` (fin des ventes, heure d'Haïti), jours
  `0 = dimanche … 6` convertis en ISO.
- `GET /results?from=&to=&page=&limit=100` → résultats paginés. Chaque tirage donne trois numéros
  (`["125", "07", "41"]`) : lots = `games.bolet` (`25`, `07`, `41`), pick 3 = `125`, pick 4 = `0741`. Un résultat dont
  les lots ne correspondent pas aux numéros est écarté (`lottery.invalid_result`).
- Erreurs : 401/403 → `unauthorized` ; 429 (quota du mois) et 5xx → `unavailable` ; réponse non conforme →
  `invalid_response`. La clé n'apparaît jamais dans les journaux.
- Une correction côté fournisseur (`updated_at`) n'écrase pas le résultat importé : elle apparaît en
  `lottery.result_conflict`.

## Synchronisation (`lottery:sync`)

Planifiée par `LOTTERY_SYNC_CRON` (toutes les 5 minutes par défaut) quand `LOTTERY_ENABLED=true`. Relit les dates UTC
d'aujourd'hui et des `LOTTERY_RESULTS_DAYS_BACK` jours précédents (`--days=N` à la main, 31 au plus).

Règles de sûreté (les résultats paieront les tickets à l'étape 2) :

- un résultat n'est accepté que pour un tirage connu, un jour où il a lieu, **après l'heure du tirage** ;
- chaque lot fait exactement deux chiffres (pick 3 : trois, pick 4 : quatre) ; sinon `lottery.result_rejected` ;
- un résultat importé **n'est jamais écrasé** : d'autres numéros pour le même tirage = `lottery.result_conflict`
  (log en erreur), le premier est gardé ; la correction passera par l'administration (étape 2) ;
- une liste de tirages vide ne désactive rien ; un tirage absent d'une liste non vide passe inactif.

## Routes publiques (contrat : `docs/api/openapi.yaml`, tag Lottery)

- `GET /api/v1/lottery/draws` : tirages actifs par heure, prochain tirage (UTC) et dernier résultat.
- `GET /api/v1/lottery/results?draw=&date=&per_page=` : résultats, du plus récent au plus ancien, paginés.

Les lots sont toujours des chaînes (« 07 » garde son zéro).
