#!/usr/bin/env bash
# Sauvegarde quotidienne chiffrée, envoyée hors du serveur (V2-F02, décision D-10 §5).
#
#   OFFSITE_REMOTE=<destination rclone> AGE_RECIPIENTS_FILE=<clés publiques> scripts/db/offsite-backup.sh [base]
#
# Étapes :
#   1. backup.sh : dump de la base, empreinte (.sha256) et manifeste (lignes par table) ;
#   2. archive des pièces KYC (volume kyc_documents), sauf KYC_BACKUP=0 ;
#   3. un seul fichier chiffré avec age pour chaque clé publique de AGE_RECIPIENTS_FILE (une par personne habilitée :
#      chacune déchiffre avec SA clé privée, qui n'est jamais sur le serveur) ;
#   4. envoi avec rclone dans <destination>/daily/, et dans <destination>/monthly/ pour la première du mois ;
#   5. conservation : 30 jours en daily/, 365 jours en monthly/ (seulement à la destination) ;
#   6. signal à healthchecks.io (HEALTHCHECK_URL, facultatif) : succès, ou /fail en cas d'erreur. Sans signal dans
#      les délais, healthchecks.io alerte (sauvegarde manquante).
#
# Le dump en clair n'existe que le temps de l'opération, dans un dossier d'accès restreint, supprimé à la fin quoi
# qu'il arrive. Rien n'est jamais supprimé de la base ni du volume KYC.
#
# Variables :
#   OFFSITE_REMOTE        destination rclone (ex. b2:reyel16-sauvegardes/production) ou dossier local (essais)
#   AGE_RECIPIENTS_FILE   fichier des clés publiques age (une par ligne)
#   BACKUP_DIR            dossier de travail local (défaut : backups)
#   KYC_BACKUP            1 (défaut) ou 0 ; KYC_DIR : dossier KYC sur la machine, sinon lu dans le conteneur backend
#   RETENTION_DAILY_DAYS  30 (défaut) ; RETENTION_MONTHLY_DAYS : 365 (défaut)
#   HEALTHCHECK_URL       adresse de ping healthchecks.io (facultatif)
#   AGE_BIN, RCLONE_BIN   programmes (défaut : age, rclone)

. "$(dirname "$0")/lib.sh"

DB="${1:-${PGDATABASE:-reyel16}}"
REMOTE="${OFFSITE_REMOTE:-}"
RECIPIENTS="${AGE_RECIPIENTS_FILE:-}"
DIR="${BACKUP_DIR:-backups}"
AGE="${AGE_BIN:-age}"
RCLONE="${RCLONE_BIN:-rclone}"
DAILY_DAYS="${RETENTION_DAILY_DAYS:-30}"
MONTHLY_DAYS="${RETENTION_MONTHLY_DAYS:-365}"

ping_healthcheck() {
    [ -n "${HEALTHCHECK_URL:-}" ] || return 0
    curl -fsS -m 10 --retry 3 -o /dev/null "${HEALTHCHECK_URL}$1" || echo "Attention : signal healthchecks.io non envoyé." >&2
}

[ -n "$REMOTE" ] || fail "OFFSITE_REMOTE manquant (destination rclone ou dossier)."
[ -s "$RECIPIENTS" ] || fail "AGE_RECIPIENTS_FILE manquant ou vide (clés publiques age)."
grep -q 'AGE-SECRET-KEY' "$RECIPIENTS" && fail "AGE_RECIPIENTS_FILE contient une clé PRIVÉE : seules les clés publiques vont sur le serveur."
command -v "$AGE" > /dev/null || fail "age introuvable ($AGE)."
command -v "$RCLONE" > /dev/null || fail "rclone introuvable ($RCLONE)."

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="$DB-$STAMP.tar.age"
mkdir -p "$DIR"
chmod 700 "$DIR" 2>/dev/null || true
WORK="$(mktemp -d "$DIR/offsite-$STAMP.XXXX")"
chmod 700 "$WORK" 2>/dev/null || true
mkdir "$WORK/content"

# En clair : jamais conservé. Toute sortie en erreur (fail compris) : signal /fail à healthchecks.io.
finish() {
    local status=$?
    rm -rf "$WORK"
    [ "$status" -eq 0 ] || ping_healthcheck /fail
}
trap finish EXIT

start=$(now_seconds)
ping_healthcheck /start

# 1. Base : dump, empreinte, manifeste.
BACKUP_DIR="$WORK/content" "$(dirname "$0")/backup.sh" "$DB"

# 2. Pièces KYC (déjà chiffrées par l'application ; chiffrées une seconde fois avec l'archive).
if [ "${KYC_BACKUP:-1}" = "1" ]; then
    if [ -n "${KYC_DIR:-}" ]; then
        tar -C "$KYC_DIR" -cf - . > "$WORK/content/kyc.tar"
    else
        docker compose exec -T backend tar -C storage/app/kyc -cf - . > "$WORK/content/kyc.tar"
    fi
    echo "Pièces KYC : $(tar -tf - < "$WORK/content/kyc.tar" | grep -cv '/$' || true) fichier(s)."
fi

# 3. Chiffrement : une archive, une clé publique par personne habilitée.
tar -C "$WORK/content" -cf - . | "$AGE" -R "$RECIPIENTS" -o "$WORK/$NAME"
[ -s "$WORK/$NAME" ] || fail "archive chiffrée vide."

# 4. Envoi, puis contrôle de la taille à la destination.
"$RCLONE" copyto "$WORK/$NAME" "$REMOTE/daily/$NAME"
local_size=$(wc -c < "$WORK/$NAME" | tr -d ' ')
remote_size=$("$RCLONE" size --json "$REMOTE/daily/$NAME" | sed -E 's/.*"bytes":([0-9]+).*/\1/')
[ "$local_size" = "$remote_size" ] || fail "taille différente à la destination ($local_size ≠ $remote_size)."

MONTH="${STAMP:0:6}"
if ! "$RCLONE" lsf "$REMOTE/monthly/" 2> /dev/null | grep -q -- "-$MONTH"; then
    "$RCLONE" copyto "$WORK/$NAME" "$REMOTE/monthly/$NAME"
    echo "Première sauvegarde du mois : copie mensuelle gardée $MONTHLY_DAYS jours."
fi

# 5. Conservation, à la destination seulement.
"$RCLONE" delete --min-age "${DAILY_DAYS}d" "$REMOTE/daily/"
"$RCLONE" delete --min-age "${MONTHLY_DAYS}d" "$REMOTE/monthly/"

elapsed=$(( $(now_seconds) - start ))
echo "OK : $NAME ($(du -h "$WORK/$NAME" | cut -f1)) envoyé vers $REMOTE en ${elapsed} s."

# 6. Succès.
ping_healthcheck ""
