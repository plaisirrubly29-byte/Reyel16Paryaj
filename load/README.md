# Tests de charge — P8-03

Scripts [k6](https://k6.io) (lancés dans le conteneur officiel `grafana/k6`) pour le catalogue public et `POST /bets`.
Résultats et analyse : [`docs/qa/P8-03-test-de-charge.md`](../docs/qa/P8-03-test-de-charge.md) — paris : objectif atteint (p95 130 ms) ;
catalogue : objectif tenu jusqu'à ~480 visiteurs simultanés sur la machine de mesure.

| Script | Charge | Objectif (seuils k6 : le passage échoue s'ils ne sont pas tenus) |
|---|---|---|
| `catalogue.js` | Montée à 1 000 visiteurs simultanés en 1 min, palier de 3 min ; chaque visiteur : sports, matchs, marchés d'un match, 1 à 3 s de lecture entre les pages | p95 < 200 ms, erreurs < 0,5 % |
| `bets.js` | 30 paris/s pendant 3 min (taux d'arrivée constant), 200 joueurs inscrits avant la mesure (sessions reprises), mise de 10 HTG | `POST /bets` : p95 < 500 ms, erreurs < 0,5 % |

## Pile testée : les images de production, jamais un serveur partagé

Le test vise la pile de Staging (`docker/staging/docker-compose.yml`, images `docker/production/Dockerfile`), lancée
dans un **projet Docker isolé** puis détruit. Il crée des comptes et des paris (fonds fictifs) : ne jamais le lancer
contre la Production.

**Une IP par visiteur.** Les limites de débit sont comptées par IP (catalogue : 120 requêtes/min). Pour ne pas les
désactiver, chaque visiteur virtuel envoie sa propre adresse dans `X-Forwarded-For`. Sur la pile de test seulement :

- `stack/docker-compose.load.yml` remplace le Caddyfile par `stack/Caddyfile` : identique à la production, sauf
  `trusted_proxies` limité au générateur de charge (`127.0.0.1`, `::1`), qui tourne dans le conteneur `web` ;
- `.env` de la pile : `TRUSTED_PROXIES=127.0.0.1,::1`.

Vérification faite : 125 requêtes avec la même IP → 5 refus (429) ; avec 125 IP différentes → aucun.

## Lancer

1. Construire les images et préparer un dossier de pile (comme `/opt/reyel16-staging`, voir
   `docs/ops/deploiement-staging.md`) avec `docker-compose.yml`, `staging-deploy.sh`, `scripts/db/`, les deux fichiers de
   `load/stack/` et un `.env` de test (`SITE_ADDRESS=localhost`, `TRUSTED_PROXIES=127.0.0.1,::1`,
   `COMPOSE_PROJECT_NAME=reyel16-load`, `COMPOSE_FILE=docker-compose.yml:docker-compose.load.yml`,
   `COMPOSE_PATH_SEPARATOR=:`).
2. Déployer puis remplir le catalogue :
   ```sh
   DEPLOY_SKIP_PULL=1 ./staging-deploy.sh deploy local
   IMAGE_TAG=local docker compose exec backend php artisan sports-data:sync
   ```
3. Lancer k6 dans le réseau du conteneur `web` (le site est alors `https://localhost`, certificat interne) :
   ```sh
   docker run --rm --network container:reyel16-load-web-1 -v "$PWD/load:/scripts:ro" -v "$PWD/results:/results" \
     -e INSECURE_TLS=1 grafana/k6:0.54.0 run --summary-export=/results/catalogue.json /scripts/catalogue.js
   docker run … -e INSECURE_TLS=1 grafana/k6:0.54.0 run --summary-export=/results/bets.json /scripts/bets.js
   ```
4. Détruire la pile de test (`docker compose down -v` dans son dossier).

| Variable | Script | Défaut |
|---|---|---|
| `BASE_URL` | tous | `https://localhost` |
| `INSECURE_TLS=1` | tous | certificat interne de Caddy accepté |
| `VISITORS`, `RAMP`, `HOLD` | catalogue | `1000`, `1m`, `3m` |
| `RATE`, `PLAYERS`, `DURATION` | bets | `30`, `200`, `3m` |
| `DEBUG=1` | bets | affiche la réponse des paris refusés |

La pile de test doit reprendre les réglages du modèle de `.env` du Staging (`DB_PERSISTENT=true`,
`CATALOGUE_CACHE_SECONDS=10`, `PHP_FPM_MAX_CHILDREN`) : sans eux, les mesures retombent aux valeurs de départ du rapport.
