#!/usr/bin/env bash
# Restauration d'une sauvegarde hors site (V2-F02) dans une base NOUVELLE, puis vérification.
#
#   AGE_IDENTITY=<clé privée> scripts/db/offsite-restore.sh <archive .tar.age> <base_cible>
#
#   <archive> : chemin rclone (ex. b2:reyel16-sauvegardes/production/daily/reyel16-….tar.age) ou fichier local.
#
# Étapes : téléchargement, déchiffrement (age, clé privée d'une personne habilitée), empreinte vérifiée et
# restauration dans une base nouvelle (restore.sh : jamais d'écrasement, bases protégées refusées), vérification
# (verify.sh : tables, lignes, déclencheurs, ledger). Les pièces KYC sont extraites dans un dossier à part
# (KYC_RESTORE_DIR), jamais dans le volume en service. Durée de chaque étape affichée (RTO).
#
# Variables : AGE_IDENTITY (fichier de la clé privée, à garder hors du serveur), KYC_RESTORE_DIR (défaut : dossier
# de travail, supprimé à la fin), BACKUP_DIR (défaut : backups), KEEP_WORK=1 pour garder le dossier de travail,
# AGE_BIN, RCLONE_BIN.

. "$(dirname "$0")/lib.sh"

ARCHIVE="${1:-}"
TARGET="${2:-}"
IDENTITY="${AGE_IDENTITY:-}"
DIR="${BACKUP_DIR:-backups}"
AGE="${AGE_BIN:-age}"
RCLONE="${RCLONE_BIN:-rclone}"

[ -n "$ARCHIVE" ] && [ -n "$TARGET" ] || fail "usage : $0 <archive .tar.age> <base_cible>"
[ -s "$IDENTITY" ] || fail "AGE_IDENTITY manquant (fichier de la clé privée age)."
command -v "$AGE" > /dev/null || fail "age introuvable ($AGE)."

mkdir -p "$DIR"
WORK="$(mktemp -d "$DIR/restore-$(date -u +%Y%m%dT%H%M%SZ).XXXX")"
chmod 700 "$WORK" 2>/dev/null || true
[ "${KEEP_WORK:-0}" = "1" ] || trap 'rm -rf "$WORK"' EXIT

total=$(now_seconds)

# 1. Téléchargement (ou copie d'un fichier local).
t=$(now_seconds)
if [ -f "$ARCHIVE" ]; then
    cp "$ARCHIVE" "$WORK/archive.tar.age"
else
    command -v "$RCLONE" > /dev/null || fail "rclone introuvable ($RCLONE)."
    "$RCLONE" copyto "$ARCHIVE" "$WORK/archive.tar.age"
fi
echo "Téléchargement : $(( $(now_seconds) - t )) s."

# 2. Déchiffrement : échoue si l'archive a été modifiée (age authentifie le contenu) ou si la clé n'est pas la bonne.
t=$(now_seconds)
mkdir "$WORK/content"
"$AGE" -d -i "$IDENTITY" "$WORK/archive.tar.age" | tar -C "$WORK/content" -xf - \
    || fail "déchiffrement impossible : mauvaise clé ou archive altérée."
echo "Déchiffrement : $(( $(now_seconds) - t )) s."

DUMP="$(ls "$WORK"/content/*.dump 2> /dev/null | head -1)"
[ -n "$DUMP" ] || fail "aucun dump dans l'archive."

# 3. Restauration dans une base nouvelle (empreinte vérifiée par restore.sh), puis 4. vérification.
t=$(now_seconds)
"$(dirname "$0")/restore.sh" "$DUMP" "$TARGET"
echo "Restauration : $(( $(now_seconds) - t )) s."

t=$(now_seconds)
"$(dirname "$0")/verify.sh" "${DUMP%.dump}.manifest" "$TARGET"
echo "Vérification : $(( $(now_seconds) - t )) s."

# 5. Pièces KYC : extraites à part, jamais dans le volume en service.
if [ -f "$WORK/content/kyc.tar" ]; then
    KYC_OUT="${KYC_RESTORE_DIR:-$WORK/kyc}"
    mkdir -p "$KYC_OUT"
    tar -C "$KYC_OUT" -xf - < "$WORK/content/kyc.tar"
    echo "Pièces KYC : $(find "$KYC_OUT" -type f | wc -l | tr -d ' ') fichier(s) extrait(s) dans $KYC_OUT."
fi

echo "OK : restauration complète en $(( $(now_seconds) - total )) s dans « $TARGET »."
