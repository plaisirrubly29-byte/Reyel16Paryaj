#!/usr/bin/env bash
# Déploiement du Staging (P1-06), exécuté SUR le serveur, depuis le dossier de déploiement (ex. /opt/reyel16-staging)
# où .github/workflows/deploy-staging.yml a copié ce script, docker-compose.yml et scripts/db/.
#
#   ./staging-deploy.sh deploy <tag>   déploie les images <tag> (ex. sha-1a2b3c4d5e6f)
#   ./staging-deploy.sh rollback       remet en service la version précédente (images seulement : la base n'est pas restaurée)
#   ./staging-deploy.sh status         version en service et état des conteneurs
#
# deploy : images → base et Redis → sauvegarde avant migration → migrations et rôles → démarrage (contrôles de santé
# des conteneurs) → contrôle HTTPS de l'API. Échec avant le démarrage : l'ancienne version n'a pas été arrêtée.
# Échec après : retour automatique à la version précédente. Rien n'est supprimé (volumes, sauvegardes, images).
#
# Variables facultatives (essais locaux) : DEPLOY_SKIP_PULL=1, HEALTHCHECK_URL, HEALTHCHECK_CURL_OPTS.

set -euo pipefail

cd "$(dirname "$0")"
STATE_DIR=.deploy

log() { printf '\n==> %s\n' "$*"; }
fail() { echo "ERREUR : $*" >&2; exit 1; }
# Valeur d'une variable du .env (guillemets et fin de ligne Windows retirés), sans exécuter le fichier.
env_get() { sed -n "s/^$1=//p" .env | tail -n 1 | sed -e 's/\r$//' -e 's/^"\(.*\)"$/\1/'; }

[ -f .env ] || fail ".env absent (modèle : docker/staging/staging.env.example)."
[ -f docker-compose.yml ] || fail "docker-compose.yml absent."
grep -q '^APP_KEY=base64:' .env || fail "APP_KEY manquante dans .env (php artisan key:generate --show)."
[ "$(env_get APP_DEBUG)" = "false" ] || fail "APP_DEBUG doit valoir false."
[ "$(stat -c %a .env 2>/dev/null || echo 600)" = "600" ] || echo "ATTENTION : protéger le .env (chmod 600 .env)." >&2

mkdir -p "$STATE_DIR"
SITE="$(env_get SITE_ADDRESS)"
HTTPS_PORT="$(env_get HTTPS_PORT)"
HTTPS_PORT="${HTTPS_PORT:-443}"
HEALTH_URL="${HEALTHCHECK_URL:-https://$SITE/api/v1/health}"
# Par défaut, le contrôle passe par le Caddy local (sans dépendre du DNS ni du NAT), avec le vrai certificat.
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

deploy() {
    local tag="${1:-}" previous
    [[ "$tag" =~ ^[A-Za-z0-9._-]+$ ]] || fail "tag d'image invalide : « $tag »."
    previous="$(current_tag)"
    export IMAGE_TAG="$tag"

    log "Version en service : ${previous:-aucune} → $tag"
    if [ -z "${DEPLOY_SKIP_PULL:-}" ]; then
        log "Téléchargement des images"
        docker compose pull backend web
    fi

    log "Base et Redis"
    docker compose up -d --wait postgres redis

    if [ -n "$previous" ]; then
        log "Sauvegarde de la base avant migration (backups/)"
        PGUSER="$(env_get DB_USERNAME)" BACKUP_DIR=backups scripts/db/backup.sh "$(env_get DB_DATABASE)"
    fi

    log "Migrations et rôles administratifs"
    docker compose run --rm --no-deps backend php artisan migrate --force
    docker compose run --rm --no-deps backend php artisan db:seed --class=PermissionSeeder --force

    log "Démarrage de $tag"
    if start "$tag" && healthy; then
        printf '%s\n' "$previous" > "$STATE_DIR/previous"
        printf '%s\n' "$tag" > "$STATE_DIR/current"
        log "Staging en service : $tag"
        return 0
    fi

    report_failure
    if [ -n "$previous" ]; then
        log "Retour automatique à $previous (la base reste migrée : migrations compatibles requises, voir docs/ops/deploiement-staging.md)"
        if start "$previous" && healthy; then
            log "$previous de nouveau en service. Déploiement de $tag ÉCHOUÉ."
        else
            echo "ERREUR : le retour à $previous a échoué aussi : intervention manuelle (docs/ops/deploiement-staging.md)." >&2
        fi
    fi
    exit 1
}

rollback() {
    local current previous
    current="$(current_tag)"
    previous="$(previous_tag)"
    [ -n "$previous" ] || fail "aucune version précédente connue."

    log "Retour de $current à $previous (images seulement ; restauration de la base : scripts/db/restore.sh)"
    if start "$previous" && healthy; then
        printf '%s\n' "$current" > "$STATE_DIR/previous"
        printf '%s\n' "$previous" > "$STATE_DIR/current"
        log "Staging en service : $previous"
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
    deploy) deploy "${2:-}" ;;
    rollback) rollback ;;
    status) status ;;
    *) fail "usage : $0 deploy <tag> | rollback | status" ;;
esac
