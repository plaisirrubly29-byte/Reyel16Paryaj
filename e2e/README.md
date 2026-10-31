# Tests de bout en bout — P8-01

Parcours complet du joueur, dans un vrai navigateur (Playwright) et sur une vraie pile (API, base, frontend) :

**compte → événement → coupon → ticket simulé → résultat test → règlement simulé**

| Étape | Vérifié |
|---|---|
| Compte | Inscription, connexion automatique, crédit de bienvenue (5 000 HTG fictifs) |
| Événement | Un match de scénario du faux fournisseur, synchronisé, affiché avec ses cotes |
| Coupon et ticket | Sélection (victoire à domicile, cote 2.00), mise de 100 HTG, « Pari placé », solde 4 900 HTG, ticket « En attente » |
| Résultat et règlement | Score 2-1 publié, import du résultat, règlement : ticket « Gagné », solde 5 100 HTG |

## Comment le résultat est simulé

Le faux fournisseur (`SPORTS_DATA_PROVIDER=fake`) gère des **matchs de scénario**, mémorisés dans le cache de
l'application :

```sh
php artisan sports-data:fake-match create            # nouveau match unique, coup d'envoi dans une heure (JSON)
php artisan sports-data:fake-match finish <id> 2-1   # match commencé il y a deux heures, score final publié
```

Ensuite, le test lance les **vraies** commandes du planificateur : `sports-data:sync`,
`settlement:import-results --competition=soccer_epl`, `settlement:run`. Chaque exécution crée son propre match : le
test peut être rejoué sur un environnement persistant (développement, Staging). La commande est refusée en
production et avec un vrai fournisseur.

## Lancer

| Variable | Rôle | Défaut |
|---|---|---|
| `E2E_BASE_URL` | Site testé | `http://localhost:5173` (docker compose) |
| `E2E_ARTISAN` | Commande artisan du **même** environnement | `docker compose exec -T backend php artisan` |
| `E2E_EXPECT_TIMEOUT`, `E2E_TEST_TIMEOUT` | Délais (ms) pour une pile lente | 30 000 et 180 000 |
| `E2E_HOST_RESOLVER_RULES` | Navigateur dans un conteneur : `MAP localhost host.docker.internal` | — |
| `E2E_IGNORE_HTTPS_ERRORS` | `1` pour un certificat local (Staging en essai) | — |

Avec Node 22 sur le poste, la pile `docker compose` démarrée :

```sh
cd e2e
npm ci
npx playwright install chromium
npx playwright test          # rapport : npx playwright show-report
```

La pile de développement (`artisan serve`, une requête à la fois) est lente : prévoir
`E2E_EXPECT_TIMEOUT=90000 E2E_TEST_TIMEOUT=600000`.

Sans Node sur le poste, depuis l'image Playwright (le socket Docker sert aux commandes artisan) :

```sh
docker run --rm --add-host=host.docker.internal:host-gateway -v /var/run/docker.sock:/var/run/docker.sock \
  -v "$PWD/e2e:/src:ro" -e E2E_HOST_RESOLVER_RULES="MAP localhost host.docker.internal" \
  -e E2E_EXPECT_TIMEOUT=90000 -e E2E_TEST_TIMEOUT=600000 \
  -e E2E_ARTISAN="docker exec -i reyel16paryaj-backend-1 php artisan" \
  mcr.microsoft.com/playwright:v1.49.1-jammy bash -c '
    curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-27.3.1.tgz | tar xz -C /tmp && cp /tmp/docker/docker /usr/local/bin/
    cp -r /src /work && cd /work && npm ci && npx playwright test'
```

Le test crée un compte joueur, un match et un ticket : ce sont des données fictives, rien n'est supprimé.

## En CI

Job **E2E (parcours complet, Playwright)** de `.github/workflows/ci.yml`, à chaque PR et push : PostgreSQL et Redis
en services, backend `artisan serve`, frontend **construit pour la production** et servi par `vite preview` (API sur
la même origine, comme en Staging), puis `npx playwright test` avec `E2E_ARTISAN="php backend/artisan"`. En cas
d'échec : rapport, traces et journaux en artefact `e2e-rapport` (7 jours).

## Sur le Staging

Une fois le Staging en service (P1-06), le même test s'exécute contre lui :
`E2E_BASE_URL=https://staging.<domaine>` et
`E2E_ARTISAN="ssh staging 'cd /opt/reyel16-staging && IMAGE_TAG=\$(cat .deploy/current) docker compose exec -T backend php artisan'"`.
Le Staging doit utiliser le faux fournisseur (`SPORTS_DATA_PROVIDER=fake`, valeur par défaut du modèle de `.env`).
