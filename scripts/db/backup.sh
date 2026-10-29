#!/usr/bin/env bash
# Sauvegarde complète d'une base PostgreSQL (P8-04) — lecture seule sur la base sauvegardée.
#
#   scripts/db/backup.sh [base]          (défaut : reyel16 ; dossier : $BACKUP_DIR, défaut backups/)
#
# Produit, avec le même préfixe horodaté (UTC) :
#   <base>-<date>.dump      format personnalisé de pg_dump (compressé, restaurable table par table)
#   <base>-<date>.sha256    empreinte du fichier, vérifiée avant toute restauration
#   <base>-<date>.manifest  nombre de lignes de chaque table, pour vérifier la restauration (verify.sh)
#
# Le dump contient des données personnelles : jamais dans Git (backups/ est ignoré), chiffré avant tout
# envoi hors du serveur (voir docs/ops/sauvegarde-restauration.md).

. "$(dirname "$0")/lib.sh"

DB="${1:-${PGDATABASE:-reyel16}}"
DIR="${BACKUP_DIR:-backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$DIR/$DB-$STAMP"

mkdir -p "$DIR"
chmod 700 "$DIR" 2>/dev/null || true

db_exists "$DB" || fail "la base « $DB » n'existe pas."

echo "Sauvegarde de « $DB » vers $BASE.dump"
start=$(now_seconds)

table_counts "$DB" > "$BASE.manifest"
pg pg_dump -U "$PGUSER" -d "$DB" --format=custom --no-owner --no-privileges > "$BASE.dump"

[ -s "$BASE.dump" ] || fail "dump vide."
# Contrôle de lisibilité de l'archive (sans rien restaurer).
pg pg_restore --list < "$BASE.dump" > /dev/null || fail "archive illisible."

(cd "$DIR" && sha256sum "$DB-$STAMP.dump" > "$DB-$STAMP.sha256")

elapsed=$(( $(now_seconds) - start ))
echo "OK : $(du -h "$BASE.dump" | cut -f1) en ${elapsed} s — $(wc -l < "$BASE.manifest" | tr -d ' ') tables, manifeste et empreinte écrits."
