# Déploiement du Staging — P1-06

| | |
|---|---|
| **Tâche** | P1-06 — Déploiement Staging automatisé |
| **Référence** | [D-10](../decisions/D-10-hebergement.md) : Staging sur un VPS, Docker Compose, HTTPS (Caddy + Let's Encrypt), données fictives |
| **Workflow** | [`.github/workflows/deploy-staging.yml`](../../.github/workflows/deploy-staging.yml) |
| **Serveur** | [`docker/staging/docker-compose.yml`](../../docker/staging/docker-compose.yml), [`scripts/deploy/staging-deploy.sh`](../../scripts/deploy/staging-deploy.sh) |
| **Images** | [`docker/production/Dockerfile`](../../docker/production/Dockerfile) (cibles `backend` et `web`) |

Le Staging ne contient **que des données et des fonds fictifs**. La Production (base managée, D-10) aura sa propre
procédure ; rien ici ne la touche.

## 1. Vue d'ensemble

```
push sur develop
  └─ Deploy Staging (GitHub Actions)
       1. Tests        : CI complète (ci.yml) sur le commit exact — backend, migrations, contrat API, frontend
       2. Images       : backend (php-fpm) et web (Caddy + frontend), tag immuable sha-<12 caractères>, publiées sur GHCR
                         après vérification (extensions PHP, artisan, config php-fpm, Caddyfile, index.html)
       3. Déploiement  : SSH vers le serveur → staging-deploy.sh deploy <tag>
       4. Contrôle     : https://<staging>/api/v1/health et page d'accueil, depuis Internet
```

Sur le serveur, `staging-deploy.sh deploy <tag>` :

1. télécharge les images `<tag>` ;
2. démarre (ou garde) PostgreSQL et Redis ;
3. **sauvegarde la base** avant migration (`scripts/db/backup.sh` → `backups/` du serveur) ;
4. lance les migrations (`migrate --force`) et les rôles (`PermissionSeeder`, idempotent) ;
5. remplace les conteneurs `backend`, `queue`, `scheduler` et `web`, et attend leurs contrôles de santé ;
6. vérifie l'API en HTTPS à travers Caddy, php-fpm et Laravel.

| Échec | Conséquence |
|---|---|
| Tests, build ou vérification d'image | Rien n'est publié ni déployé |
| Téléchargement, sauvegarde ou migration | L'ancienne version n'a pas été arrêtée : elle reste en service |
| Démarrage ou contrôle de santé | **Retour automatique** à la version précédente (images) ; le workflow échoue |

Rien n'est jamais supprimé par le déploiement : volumes (base, Redis, certificats), sauvegardes et anciennes images
restent sur le serveur.

### Architecture sur le serveur

| Conteneur | Rôle | Exposé |
|---|---|---|
| `web` (Caddy) | HTTPS (Let's Encrypt), frontend statique, `/api`, `/sanctum` et `/up` vers php-fpm | 80, 443 |
| `backend` | API Laravel (php-fpm) | non |
| `queue` | `php artisan queue:work` | non |
| `scheduler` | `php artisan schedule:work` (synchronisation, résultats, règlement) | non |
| `postgres`, `redis` | Données (volumes `pgdata`, `redisdata`), mots de passe obligatoires | non |

Le frontend appelle l'API sur la **même origine** (`/api/v1`) : ni CORS ni cookie tiers. Caddy transmet l'adresse
réelle du client à php-fpm (FastCGI) : aucun proxy de confiance à déclarer (`TRUSTED_PROXIES` vide).

### Réglages de performance (test de charge P8-03)

| Variable du `.env` | Valeur du modèle | Rôle |
|---|---|---|
| `DB_PERSISTENT` | `true` | Une connexion PostgreSQL par processus php-fpm au lieu d'une par requête |
| `CATALOGUE_CACHE_SECONDS` | `10` | Réponses du catalogue public servies depuis Redis pendant 10 s (le pari revérifie toujours la cote) |
| `PHP_FPM_MAX_CHILDREN` | `4` (2 vCPU) | Processus php-fpm au plus : environ 2 par vCPU |

Capacité mesurée et limites : [`docs/qa/P8-03-test-de-charge.md`](../qa/P8-03-test-de-charge.md).

## 2. Secrets

| Secret | Où | Jamais |
|---|---|---|
| `.env` de l'application (APP_KEY, mots de passe base et Redis…) | **Serveur uniquement** : `/opt/reyel16-staging/.env`, `chmod 600` | Dans Git, dans GitHub, dans une image |
| Clé SSH de déploiement | Secret GitHub `STAGING_SSH_KEY` (environnement `staging`) | Ailleurs que dans les secrets |
| Empreinte du serveur | Secret `STAGING_SSH_KNOWN_HOSTS` (vérifiée : pas de `StrictHostKeyChecking=no`) | — |
| Accès au registre GHCR | Jeton du job (`GITHUB_TOKEN`), valable le temps du déploiement, retiré du serveur ensuite | Jeton permanent sur le serveur |

Les images ne contiennent aucun secret (`.dockerignore` exclut `.env`, sauvegardes et dépendances locales) : la
configuration est lue au démarrage des conteneurs, qui construisent alors les caches Laravel.

## 3. Mise en place (une seule fois)

### 3.1 Serveur (VPS 2 vCPU / 4 Go, D-10)

1. Docker Engine et le plugin Compose installés ; pare-feu : 22 (SSH), 80 et 443 seulement.
2. Utilisateur de déploiement dédié, membre du groupe `docker`, connexion par clé uniquement :
   ```sh
   sudo adduser --disabled-password deploy && sudo usermod -aG docker deploy
   sudo mkdir -p /opt/reyel16-staging && sudo chown deploy: /opt/reyel16-staging
   ```
3. Clé SSH réservée au déploiement (sur un poste de confiance), clé publique dans `~deploy/.ssh/authorized_keys` :
   ```sh
   ssh-keygen -t ed25519 -f staging_deploy -N "" -C "github-actions staging"
   ```
4. DNS : enregistrement A (et AAAA) du nom de Staging vers le serveur, **avant** le premier déploiement
   (Caddy obtient le certificat Let's Encrypt au démarrage).
5. Fichier `/opt/reyel16-staging/.env` à partir de [`docker/staging/staging.env.example`](../../docker/staging/staging.env.example) :
   `APP_KEY` (`php artisan key:generate --show`), mots de passe (`openssl rand -hex 32`, sans `$`), domaine, e-mail
   Let's Encrypt. Puis `chmod 600 .env`.

### 3.2 GitHub

| Type | Nom | Valeur |
|---|---|---|
| Variable de dépôt | `STAGING_ENABLED` | `true` (active le workflow ; absent = inactif) |
| Environnement | `staging` | Settings → Environments ; restreindre aux déploiements depuis `develop` |
| Secret (environnement `staging`) | `STAGING_SSH_HOST` | IP ou nom du serveur |
| | `STAGING_SSH_USER` | `deploy` |
| | `STAGING_SSH_KEY` | Contenu de la clé privée `staging_deploy` |
| | `STAGING_SSH_KNOWN_HOSTS` | Sortie de `ssh-keyscan -t ed25519 <serveur>`, **comparée** à l'empreinte affichée sur le serveur |
| Variable (environnement `staging`) | `STAGING_URL` | `https://staging.<domaine>` (sans `/` final) |
| | `STAGING_DEPLOY_DIR` | Facultatif, défaut `/opt/reyel16-staging` |

Les images sont publiées dans les paquets GitHub du dépôt (`ghcr.io/<propriétaire>/reyel16paryaj-backend` et `-web`),
privés par défaut.

### 3.3 Premier déploiement et premier administrateur

1. Pousser sur `develop` (ou lancer le workflow à la main, voir §4) : la base est créée et migrée, sans comptes de
   démonstration (seuls les rôles sont créés).
2. S'inscrire sur le Staging avec son compte, puis lui donner le rôle Super Admin (commande auditée) :
   ```sh
   cd /opt/reyel16-staging
   IMAGE_TAG=$(cat .deploy/current) docker compose exec backend php artisan identity:grant-role <téléphone ou e-mail> super_admin --reason="Premier administrateur du Staging"
   ```

## 4. Opérations courantes

| Besoin | Comment |
|---|---|
| Déployer develop | Automatique à chaque push (après fusion d'une PR) |
| Redéployer / revenir à une version | Actions → **Deploy Staging** → Run workflow, `image_tag` = `sha-<12 caractères>` d'un déploiement réussi (sans reconstruire ni rejouer les tests) |
| Retour rapide sur le serveur | `./staging-deploy.sh rollback` (version précédente) |
| Version en service | `./staging-deploy.sh status` (fichiers `.deploy/current` et `.deploy/previous`) |
| Logs | `IMAGE_TAG=$(cat .deploy/current) docker compose logs -f backend queue scheduler web` |

> Le lancement manuel (Run workflow) n'apparaît dans GitHub qu'une fois `deploy-staging.yml` présent sur la branche
> par défaut (`main`). Le déploiement automatique sur push de `develop` fonctionne dès la fusion dans `develop`.

### Retour arrière et base de données

Le retour arrière remet les **images** précédentes ; il ne défait pas les migrations. D'où la règle : une migration
doit rester compatible avec la version précédente du code (ajouter avant de supprimer, en deux déploiements).

Si une migration a abîmé les données, la sauvegarde prise juste avant est dans `backups/` du serveur. Restaurer dans
une base **neuve** puis basculer (le script refuse d'écraser une base existante) :
```sh
cd /opt/reyel16-staging && export IMAGE_TAG=$(cat .deploy/current)
ls -t backups/*.dump | head -3
PGUSER=reyel16 scripts/db/restore.sh backups/<fichier>.dump reyel16_restauree
```
puis suivre [sauvegarde-restauration.md](sauvegarde-restauration.md). Ces sauvegardes contiennent des données
personnelles (fictives en Staging) : ne pas les copier hors du serveur sans chiffrement. Elles s'accumulent à chaque
déploiement : les purger régulièrement (politique de rétention de P8-04).

## 5. Vérifications faites (P1-06)

- `actionlint` (workflows) et `shellcheck` (scripts) sans remarque.
- Images construites localement ; pile Staging complète lancée localement (`SITE_ADDRESS=localhost`, ports 8080/8443) :
  déploiement, migrations, contrôle HTTPS, puis échec provoqué → retour automatique à la version précédente.
- Reste à faire avec le serveur réel : HTTPS Let's Encrypt et accès depuis Internet (critère « Staging accessible en HTTPS »).
