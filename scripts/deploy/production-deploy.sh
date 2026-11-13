#!/usr/bin/env bash
# Déploiement de la Production (V2-F01), exécuté SUR le serveur, depuis le dossier de déploiement
# (ex. /opt/reyel16-production) où .github/workflows/deploy-production.yml a copié ce script, les fichiers compose
# et scripts/db/.
#
#   ./production-deploy.sh check           vérifie la configuration (.env, outils) sans rien changer
#   ./production-deploy.sh deploy <tag>    déploie les images <tag> (ex. sha-1a2b3c4d5e6f)
#   ./production-deploy.sh rollback        remet en service la version précédente (images seulement : la base n'est pas restaurée)
#   ./production-deploy.sh status          version en service et état des conteneurs
#
# Même enchaînement que le Staging (scripts/deploy/staging-deploy.sh), avec en plus :
# - configuration refusée si elle n'est pas celle d'une Production (debug, cookies, fournisseurs fictifs, SSL) ;
# - sauvegarde de la base AVANT CHAQUE migration, premier déploiement compris ; un échec de sauvegarde arrête tout ;
# - base PostgreSQL managée par défaut (outils PostgreSQL 16 du serveur, connexion SSL), ou conteneur local
#   avec docker-compose.local-db.yml (COMPOSE_FILE dans le .env) ;
# - deux comptes de base (revue P8-02, F9) : le compte PROPRIÉTAIRE de .env.migration sauvegarde, migre et règle
#   les droits ; l'application en marche n'utilise que le compte de .env, sans droit de modifier, effacer ou vider
#   les tables en ajout seul (scripts/db/restrict-app-role.sql), et ne voit jamais le mot de passe du propriétaire.
#
# deploy : contrôles → images → Redis (et base locale) → sauvegarde → migrations et rôles → droits du compte de
# l'application → démarrage (contrôles de santé) → contrôle HTTPS de l'API. Échec avant le démarrage : l'ancienne
# version n'a pas été arrêtée.
# Échec après : retour automatique à la version précédente. Rien n'est supprimé (volumes, sauvegardes, images).
#
# Variables facultatives (essais locaux) : DEPLOY_SKIP_PULL=1, HEALTHCHECK_URL, HEALTHCHECK_CURL_OPTS.

set -euo pipefail

cd "$(dirname "$0")"
STATE_DIR=.deploy

log() { printf '\n==> %s\n' "$*"; }
fail() { echo "ERREUR : $*" >&2; exit 1; }
# Valeur d'une variable d'un fichier d'environnement (guillemets et fin de ligne Windows retirés), sans l'exécuter.
file_get() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -n 1 | sed -e 's/\r$//' -e 's/^"\(.*\)"$/\1/'; }
env_get() { file_get .env "$1"; }
# Compte propriétaire de la base (F9) : fichier à part, jamais chargé dans les conteneurs de l'application.
MIGRATION_ENV=.env.migration
migration_get() { file_get "$MIGRATION_ENV" "$1"; }

[ -f .env ] || fail ".env absent (modèle : docker/production/production.env.example)."
[ -f docker-compose.yml ] || fail "docker-compose.yml absent."

# La base est-elle le conteneur de docker-compose.local-db.yml (sinon : base managée) ?
local_db() { [[ "$(env_get COMPOSE_FILE)" == *docker-compose.local-db.yml* ]]; }

# Refuse toute configuration qui ne serait pas celle d'une Production. Ne modifie rien.
check_config() {
    local problems=() provider
    grep -q '^APP_KEY=base64:' .env || problems+=("APP_KEY manquante (php artisan key:generate --show)")
    [ "$(env_get APP_ENV)" = "production" ] || problems+=("APP_ENV doit valoir production")
    [ "$(env_get APP_DEBUG)" = "false" ] || problems+=("APP_DEBUG doit valoir false")
    [ "$(env_get SESSION_SECURE_COOKIE)" = "true" ] || problems+=("SESSION_SECURE_COOKIE doit valoir true")
    [ -n "$(env_get SITE_ADDRESS)" ] || problems+=("SITE_ADDRESS manquant")
    [ -n "$(env_get DB_PASSWORD)" ] || problems+=("DB_PASSWORD manquant")
    [ -n "$(env_get REDIS_PASSWORD)" ] || problems+=("REDIS_PASSWORD manquant")

    provider="$(env_get SPORTS_DATA_PROVIDER)"
    { [ -n "$provider" ] && [ "$provider" != "fake" ]; } || problems+=("SPORTS_DATA_PROVIDER : jamais le fournisseur fictif en Production")
    if [ "$(env_get LOTTERY_ENABLED)" = "true" ] && [ "$(env_get LOTTERY_PROVIDER)" != "unitysoftconnect" ]; then
        problems+=("LOTTERY_PROVIDER doit valoir unitysoftconnect quand LOTTERY_ENABLED=true")
    fi

    if local_db; then
        echo "Base : conteneur PostgreSQL local (docker-compose.local-db.yml), sans PITR — fonds fictifs seulement." >&2
        case "$(env_get DB_SSLMODE)" in
            require | verify-ca | verify-full) problems+=("DB_SSLMODE=disable avec la base locale (le conteneur n'a pas de SSL)") ;;
        esac
    else
        [ -n "$(env_get DB_HOST)" ] || problems+=("DB_HOST manquant (hôte de la base managée)")
        case "$(env_get DB_SSLMODE)" in
            require | verify-ca | verify-full) ;;
            *) problems+=("DB_SSLMODE doit valoir require, verify-ca ou verify-full vers une base managée") ;;
        esac
        command -v pg_dump > /dev/null || problems+=("pg_dump absent du serveur (paquet postgresql-client-16)")
        command -v psql > /dev/null || problems+=("psql absent du serveur (paquet postgresql-client-16)")
    fi

    # Revue P8-02, F9 : compte propriétaire séparé, hors de .env (que les conteneurs de l'application chargent).
    if [ ! -f "$MIGRATION_ENV" ]; then
        problems+=("$MIGRATION_ENV absent : compte propriétaire de la base (modèle : docker/production/migration.env.example)")
    else
        [ -n "$(migration_get DB_MIGRATION_USERNAME)" ] || problems+=("DB_MIGRATION_USERNAME manquant dans $MIGRATION_ENV")
        [ -n "$(migration_get DB_MIGRATION_PASSWORD)" ] || problems+=("DB_MIGRATION_PASSWORD manquant dans $MIGRATION_ENV")
        if [ "$(migration_get DB_MIGRATION_USERNAME)" = "$(env_get DB_USERNAME)" ]; then
            problems+=("DB_MIGRATION_USERNAME doit différer de DB_USERNAME (compte de l'application)")
        fi
        if [ -n "$(migration_get DB_MIGRATION_PASSWORD)" ] && [ "$(migration_get DB_MIGRATION_PASSWORD)" = "$(env_get DB_PASSWORD)" ]; then
            problems+=("DB_MIGRATION_PASSWORD doit différer de DB_PASSWORD")
        fi
    fi
    if grep -q '^DB_MIGRATION_' .env; then
        problems+=("DB_MIGRATION_* dans .env : à placer dans $MIGRATION_ENV seulement (.env est lu par l'application)")
    fi

    local file
    for file in .env "$MIGRATION_ENV"; do
        if [ -f "$file" ] && [ "$(stat -c %a "$file" 2>/dev/null || echo 600)" != "600" ]; then
            problems+=("$file lisible par d'autres comptes (chmod 600 $file)")
        fi
    done

    if [ "${#problems[@]}" -gt 0 ]; then
        printf 'ERREUR : configuration de Production refusée :\n' >&2
        printf '  - %s\n' "${problems[@]}" >&2
        exit 1
    fi
    echo "Configuration de Production : OK"
}

mkdir -p "$STATE_DIR"
SITE="$(env_get SITE_ADDRESS)"
HTTPS_PORT="$(env_get HTTPS_PORT)"
HTTPS_PORT="${HTTPS_PORT:-443}"
HEALTH_URL="${HEALTHCHECK_URL:-https://$SITE/api/v1/health}"
# Par défaut, le contrôle passe par le Caddy local (sans dépendre du DNS), avec le vrai certificat.
CURL_OPTS="${HEALTHCHECK_CURL_OPTS:---resolve $SITE:$HTTPS_PORT:127.0.0.1}"

current_tag() { cat "$STATE_DIR/current" 2>/dev/null || true; }
previous_tag() { cat "$STATE_DIR/previous" 2>/dev/null || true; }

# Lance la version $1 et attend que les conteneurs soient sains (healthcheck).
start() {
    export IMAGE_TAG="$1"
    docker compose up -d --remove-orphans --wait --wait-timeout 180
}

# L'API répond-elle en HTTPS, à travers Caddy, php-fpm et Laravel ?
healthy() {
    local _
    for _ in $(seq 1 20); do
        # shellcheck disable=SC2086 # options curl volontairement découpées
        if curl -fsS --max-time 5 $CURL_OPTS "$HEALTH_URL" 2>/dev/null | grep -q '"status":"ok"'; then
            return 0
        fi
        sleep 3
    done
    echo "ERREUR : $HEALTH_URL ne répond pas \"ok\"." >&2
    return 1
}

report_failure() {
    docker compose ps >&2 || true
    docker compose logs --tail 40 backend web >&2 || true
}

# Sauvegarde complète avant migration (scripts/db/backup.sh), avec le compte propriétaire : conteneur local, ou base
# managée par les outils du serveur et les variables PG* (connexion SSL). Un échec arrête le déploiement : on ne
# migre jamais sans sauvegarde.
backup_database() {
    if local_db; then
        PGUSER="$(migration_get DB_MIGRATION_USERNAME)" BACKUP_DIR=backups scripts/db/backup.sh "$(env_get DB_DATABASE)"
    else
        PGHOST="$(env_get DB_HOST)" PGPORT="$(env_get DB_PORT)" PGUSER="$(migration_get DB_MIGRATION_USERNAME)" \
            PGPASSWORD="$(migration_get DB_MIGRATION_PASSWORD)" PGSSLMODE="$(env_get DB_SSLMODE)" \
            BACKUP_DIR=backups scripts/db/backup.sh "$(env_get DB_DATABASE)"
    fi
}

# Commande artisan dans un conteneur jetable, avec le compte PROPRIÉTAIRE (migrations, rôles admin). Les
# identifiants passent par l'environnement de ce seul conteneur (-e sans valeur : pris dans celui du script), jamais
# par la ligne de commande ni par les conteneurs de l'application.
artisan_as_owner() {
    DB_USERNAME="$(migration_get DB_MIGRATION_USERNAME)" DB_PASSWORD="$(migration_get DB_MIGRATION_PASSWORD)" \
        docker compose run --rm --no-deps -e DB_USERNAME -e DB_PASSWORD backend php artisan "$@"
}

# Droits du compte de l'application, réglés par le compte propriétaire après chaque migration (revue P8-02, F9).
restrict_app_role() {
    local app db
    app="$(env_get DB_USERNAME)"
    db="$(env_get DB_DATABASE)"
    if local_db; then
        docker compose exec -T postgres psql -U "$(migration_get DB_MIGRATION_USERNAME)" -d "$db" -q \
            -v ON_ERROR_STOP=1 -v app_role="$app" < scripts/db/restrict-app-role.sql
    else
        PGHOST="$(env_get DB_HOST)" PGPORT="$(env_get DB_PORT)" PGUSER="$(migration_get DB_MIGRATION_USERNAME)" \
            PGPASSWORD="$(migration_get DB_MIGRATION_PASSWORD)" PGSSLMODE="$(env_get DB_SSLMODE)" \
            psql -d "$db" -q -v ON_ERROR_STOP=1 -v app_role="$app" -f scripts/db/restrict-app-role.sql
    fi
}

deploy() {
    local tag="${1:-}" previous
    [[ "$tag" =~ ^[A-Za-z0-9._-]+$ ]] || fail "tag d'image invalide : « $tag »."
    check_config
    previous="$(current_tag)"
    export IMAGE_TAG="$tag"

    log "Version en service : ${previous:-aucune} → $tag"
    if [ -z "${DEPLOY_SKIP_PULL:-}" ]; then
        log "Téléchargement des images"
        docker compose pull backend web
    fi

    if local_db; then
        log "Redis et base locale"
        docker compose up -d --wait redis postgres
    else
        log "Redis"
        docker compose up -d --wait redis
    fi

    log "Sauvegarde de la base avant migration (backups/)"
    backup_database

    log "Migrations et rôles administratifs (compte propriétaire)"
    artisan_as_owner migrate --force
    artisan_as_owner db:seed --class=PermissionSeeder --force

    log "Droits du compte de l'application"
    restrict_app_role

    log "Démarrage de $tag"
    if start "$tag" && healthy; then
        printf '%s\n' "$previous" > "$STATE_DIR/previous"
        printf '%s\n' "$tag" > "$STATE_DIR/current"
        log "Production en service : $tag"
        return 0
    fi

    report_failure
    if [ -n "$previous" ]; then
        log "Retour automatique à $previous (la base reste migrée : migrations compatibles requises, voir docs/ops/deploiement-production.md)"
        if start "$previous" && healthy; then
            log "$previous de nouveau en service. Déploiement de $tag ÉCHOUÉ."
        else
            echo "ERREUR : le retour à $previous a échoué aussi : intervention manuelle (docs/ops/deploiement-production.md)." >&2
        fi
    fi
    exit 1
}

rollback() {
    local current previous
    current="$(current_tag)"
    previous="$(previous_tag)"
    [ -n "$previous" ] || fail "aucune version précédente connue."

    log "Retour de $current à $previous (images seulement ; restauration de la base : docs/ops/deploiement-production.md §5)"
    if start "$previous" && healthy; then
        printf '%s\n' "$current" > "$STATE_DIR/previous"
        printf '%s\n' "$previous" > "$STATE_DIR/current"
        log "Production en service : $previous"
    else
        report_failure
        fail "le retour à $previous a échoué."
    fi
}

status() {
    export IMAGE_TAG="${IMAGE_TAG:-$(current_tag)}"
    echo "Version en service : $(current_tag) (précédente : $(previous_tag))"
    docker compose ps
}

case "${1:-}" in
    check) check_config ;;
    deploy) deploy "${2:-}" ;;
    rollback) rollback ;;
    status) status ;;
    *) fail "usage : $0 check | deploy <tag> | rollback | status" ;;
esac
