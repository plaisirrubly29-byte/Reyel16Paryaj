# Déploiement de la Production — V2-F01

| | |
|---|---|
| **Tâche** | V2-F01 (#189) — Environnement de Production, déploiement depuis `main` avec validation manuelle |
| **Référence** | [D-10](../decisions/D-10-hebergement.md) : serveur applicatif + PostgreSQL managé (PITR), HTTPS (Caddy + Let's Encrypt) ; fournisseur au §7 (V2-A03, #139) |
| **Workflow** | [`.github/workflows/deploy-production.yml`](../../.github/workflows/deploy-production.yml) |
| **Serveur** | [`docker/production/docker-compose.yml`](../../docker/production/docker-compose.yml), [`docker-compose.local-db.yml`](../../docker/production/docker-compose.local-db.yml), [`scripts/deploy/production-deploy.sh`](../../scripts/deploy/production-deploy.sh) |
| **Modèle du `.env`** | [`docker/production/production.env.example`](../../docker/production/production.env.example) |

Même chaîne que le Staging ([deploiement-staging.md](deploiement-staging.md)), avec quatre différences :

| | Staging | Production |
|---|---|---|
| Déclenchement | Automatique à chaque push sur `develop` | **Manuel**, depuis `main` seulement, puis **approbation** d'un relecteur de l'environnement GitHub `production` |
| Base de données | Conteneur PostgreSQL | **PostgreSQL managé** (connexion SSL, PITR chez l'hébergeur) ; conteneur seulement avec `docker-compose.local-db.yml` (fonds fictifs) |
| Images | `sha-<commit>`, jeux virtuels activés | `prod-sha-<commit>`, construites sans les options du Staging ; un tag du Staging est refusé |
| Contrôles | `.env` présent, `APP_DEBUG=false` | `production-deploy.sh check` : `APP_ENV=production`, `APP_DEBUG=false`, cookies sécurisés, aucun fournisseur fictif, SSL vers la base managée, `.env` en `600`, `pg_dump` présent ; sauvegarde **avant chaque** migration, premier déploiement compris |

## 1. Vue d'ensemble

```
Actions → Deploy Production → Run workflow (branche main)
  1. Préparer     : refus hors de main ; tag prod-sha-<12 caractères> (ou tag déjà publié à redéployer)
  2. Tests        : CI complète (ci.yml) sur le commit exact
  3. Images       : backend et web publiées sur GHCR après vérification
  4. Approbation  : un relecteur de l'environnement « production » valide (ou refuse) le déploiement
  5. Déploiement  : SSH → production-deploy.sh check, puis deploy <tag>
  6. Contrôle     : https://<production>/api/v1/health et page d'accueil, depuis Internet
```

`production-deploy.sh deploy <tag>` sur le serveur : contrôle de la configuration → images → Redis (et base locale)
→ **sauvegarde de la base** (`backups/`) → migrations et rôles (`PermissionSeeder`) → démarrage (contrôles de santé)
→ contrôle HTTPS.

| Échec | Conséquence |
|---|---|
| Configuration, tests, build ou approbation refusée | Rien n'est déployé |
| Téléchargement, **sauvegarde** ou migration | L'ancienne version n'a pas été arrêtée : elle reste en service ; aucune migration sans sauvegarde |
| Démarrage ou contrôle de santé | **Retour automatique** à la version précédente (images) ; le workflow échoue |

Rien n'est jamais supprimé par le déploiement : volumes, sauvegardes et anciennes images restent sur le serveur.

## 2. Secrets — séparés du Staging

| Secret | Où | Jamais |
|---|---|---|
| `.env` de l'application | **Serveur de Production uniquement** : `/opt/reyel16-production/.env`, `chmod 600` ; **aucune valeur reprise du Staging** (nouvelle `APP_KEY`, nouveaux mots de passe, clés d'API de production) | Dans Git, dans GitHub, dans une image |
| Clé SSH de déploiement | Secret `PRODUCTION_SSH_KEY` de l'environnement `production` ; **clé différente** de celle du Staging | Ailleurs que dans les secrets |
| Empreinte du serveur | Secret `PRODUCTION_SSH_KNOWN_HOSTS` (vérifiée : pas de `StrictHostKeyChecking=no`) | — |
| Mot de passe de la base managée | `.env` du serveur (`DB_PASSWORD`) ; compte applicatif sans droit d'administration de l'instance | Dans GitHub |
| Accès au registre GHCR | Jeton du job (`GITHUB_TOKEN`), retiré du serveur après le déploiement | Jeton permanent sur le serveur |

## 3. Mise en place (une seule fois, après la décision H-1 à H-5 de D-10 §7.4)

### 3.1 Serveur et base

1. Serveur applicatif (4 vCPU / 8 Go, D-10 §7.2) : Docker Engine, plugin Compose et **`postgresql-client-16`**
   (sauvegardes de la base managée) ; pare-feu : 22, 80 et 443 seulement.
2. Utilisateur `deploy` membre du groupe `docker`, connexion par clé uniquement ; dossier `/opt/reyel16-production`.
3. Clé SSH dédiée : `ssh-keygen -t ed25519 -f production_deploy -N "" -C "github-actions production"`.
4. Base PostgreSQL 16 managée **dans la même région et le même réseau privé** que le serveur, PITR activé (7 jours
   au moins), connexions limitées au serveur (liste d'adresses ou réseau privé). Base `reyel16` et compte applicatif
   `reyel16` propriétaire de cette base. Le compte doit pouvoir se connecter à la base `postgres` (contrôle
   d'existence fait par `scripts/db/backup.sh`).
5. DNS du domaine de Production vers le serveur, **avant** le premier déploiement (certificat Let's Encrypt).
6. `.env` à partir de `production.env.example`, puis `chmod 600 .env` et `./production-deploy.sh check`.

### 3.2 GitHub

| Type | Nom | Valeur |
|---|---|---|
| Variable de dépôt | `PRODUCTION_ENABLED` | `true` (active le workflow ; absent = inactif) |
| Environnement | `production` | Settings → Environments : **Required reviewers** (Rubly PLAISIR et un second relecteur), **Prevent self-review**, branches de déploiement limitées à `main` |
| Secret (environnement `production`) | `PRODUCTION_SSH_HOST` | IP ou nom du serveur |
| | `PRODUCTION_SSH_USER` | `deploy` |
| | `PRODUCTION_SSH_KEY` | Clé privée `production_deploy` |
| | `PRODUCTION_SSH_KNOWN_HOSTS` | `ssh-keyscan -t ed25519 <serveur>`, **comparée** à l'empreinte affichée sur le serveur |
| Variable (environnement `production`) | `PRODUCTION_URL` | `https://<domaine>` (sans `/` final) |
| | `PRODUCTION_DEPLOY_DIR` | Facultatif, défaut `/opt/reyel16-production` |

> Le bouton « Run workflow » n'apparaît qu'une fois `deploy-production.yml` présent sur `main`.

### 3.3 Premier administrateur

Comme au Staging (§3.3 de [deploiement-staging.md](deploiement-staging.md)) : inscription, puis
`php artisan identity:grant-role <téléphone ou e-mail> super_admin --reason="…"` sur le serveur. Deux Super Admins au
lancement (D-07), MFA obligatoire.

## 4. Opérations courantes

| Besoin | Comment |
|---|---|
| Mettre en Production | Fusionner `develop` dans `main` (PR relue), puis Actions → **Deploy Production** → Run workflow sur `main`, et approuver |
| Revenir à une version | Run workflow avec `image_tag` = `prod-sha-<12 caractères>` d'un déploiement réussi (sans reconstruire), puis approuver |
| Retour rapide sur le serveur | `./production-deploy.sh rollback` (version précédente) |
| Vérifier la configuration | `./production-deploy.sh check` (ne modifie rien) |
| Version en service | `./production-deploy.sh status` |
| Logs | `IMAGE_TAG=$(cat .deploy/current) docker compose logs -f backend queue scheduler web` |

## 5. Retour arrière et base de données

Le retour arrière remet les **images** précédentes ; il ne défait pas les migrations. Règle : une migration reste
compatible avec la version précédente du code (ajouter avant de supprimer, en deux déploiements).

Si une migration a abîmé les données :

1. **Base managée** : restauration à un instant donné (PITR) **vers une nouvelle instance**, à l'heure qui précède le
   déploiement (console de l'hébergeur), puis bascule de `DB_HOST` dans le `.env` et `./production-deploy.sh rollback`.
2. Sinon, la sauvegarde prise juste avant la migration est dans `backups/` : restauration dans une base **neuve**
   (`scripts/db/restore.sh` refuse d'écraser une base existante), puis bascule ; voir
   [sauvegarde-restauration.md](sauvegarde-restauration.md).

Les sauvegardes contiennent des données personnelles : jamais copiées hors du serveur sans chiffrement. Copie hors site
et rétention : D-10 §5 (à mettre en place avec le stockage objet choisi en H-5).

## 6. Vérifications faites (V2-F01)

- `shellcheck` (scripts) et `actionlint` (workflows de Staging et de Production) sans remarque.
- `docker compose config` : variante base managée (aucun conteneur PostgreSQL, `DB_SSLMODE=require`) et variante
  `docker-compose.local-db.yml` (conteneur `postgres`, dépendances de `backend`, `queue`, `scheduler`).
- Essai local du retour arrière (Docker, variante `docker-compose.local-db.yml`, images construites avec
  `VITE_VIRTUAL_GAMES=false`, script exécuté dans un conteneur Linux) :

  | Scénario | Résultat |
  |---|---|
  | `.env` avec `APP_DEBUG=true` et `SPORTS_DATA_PROVIDER=fake` | `check` refuse, avec les deux motifs |
  | Premier déploiement de A | Sauvegarde avant migration (39 tables), migrations, démarrage, `/api/v1/health` « ok » |
  | Déploiement de B (backend qui ne démarre pas) | Échec, **retour automatique à A**, `/api/v1/health` « ok », `.deploy/current` = A |
  | Déploiement de C, puis `rollback` | C en service, puis A ; `status` : A (précédente : C) ; 3 sauvegardes |

- Reste à faire avec le serveur réel : approbation dans l'environnement GitHub, HTTPS Let's Encrypt, connexion SSL à la
  base managée, sauvegarde par `pg_dump` du serveur et restauration PITR d'essai.
