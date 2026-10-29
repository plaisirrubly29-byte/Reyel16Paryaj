#!/usr/bin/env bash
# Restauration d'une sauvegarde dans une base NOUVELLE (P8-04).
#
#   scripts/db/restore.sh <fichier.dump> <base_cible>
#
# Garde-fous : la base cible ne doit pas exister (jamais d'écrasement) et ne peut pas être une base
# protégée ($PROTECTED_DATABASES, défaut « reyel16 postgres »). Remplacer la base en service est une
# décision d'incident, faite à la main selon docs/ops/runbook-incident.md (bascule, pas écrasement).

. "$(dirname "$0")/lib.sh"

DUMP="${1:-}"
TARGET="${2:-}"
PROTECTED="${PROTECTED_DATABASES:-reyel16 postgres template0 template1}"

[ -n "$DUMP" ] && [ -n "$TARGET" ] || fail "usage : $0 <fichier.dump> <base_cible>"
[ -f "$DUMP" ] || fail "fichier introuvable : $DUMP"
[[ "$TARGET" =~ ^[a-z][a-z0-9_]{0,62}$ ]] || fail "nom de base invalide : $TARGET"
for p in $PROTECTED; do
    [ "$TARGET" != "$p" ] || fail "« $TARGET » est une base protégée : restaurer dans une nouvelle base."
done
db_exists "$TARGET" && fail "la base « $TARGET » existe déjà : rien n'est écrasé."

SUM="${DUMP%.dump}.sha256"
if [ -f "$SUM" ]; then
    (cd "$(dirname "$DUMP")" && sha256sum --check --quiet "$(basename "$SUM")") || fail "empreinte invalide : fichier altéré."
    echo "Empreinte vérifiée."
else
    echo "Attention : pas de fichier .sha256, intégrité non vérifiée."
fi

echo "Restauration de $DUMP dans la nouvelle base « $TARGET »"
start=$(now_seconds)

pg createdb -U "$PGUSER" "$TARGET"
pg pg_restore -U "$PGUSER" -d "$TARGET" --no-owner --no-privileges --exit-on-error < "$DUMP" \
    || fail "restauration interrompue : la base « $TARGET » est incomplète (à supprimer avant un nouvel essai)."

elapsed=$(( $(now_seconds) - start ))
echo "OK : restauration en ${elapsed} s. Vérifier avec : scripts/db/verify.sh ${DUMP%.dump}.manifest $TARGET"
