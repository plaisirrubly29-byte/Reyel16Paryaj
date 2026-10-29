#!/usr/bin/env bash
# Vérification d'une base restaurée (P8-04) — lecture seule.
#
#   scripts/db/verify.sh <fichier.manifest> <base_restauree>
#
# Contrôles :
#   1. mêmes tables et même nombre de lignes que le manifeste écrit au moment de la sauvegarde ;
#   2. déclencheurs « ajout seul » présents (journal d'audit, ledger, résultats, règlements) ;
#   3. ledger cohérent : pour chaque portefeuille, le solde après la dernière écriture = somme des écritures.

. "$(dirname "$0")/lib.sh"

MANIFEST="${1:-}"
TARGET="${2:-}"
[ -n "$MANIFEST" ] && [ -n "$TARGET" ] || fail "usage : $0 <fichier.manifest> <base_restauree>"
[ -f "$MANIFEST" ] || fail "manifeste introuvable : $MANIFEST"
db_exists "$TARGET" || fail "la base « $TARGET » n'existe pas."

errors=0

echo "1. Tables et lignes"
if diff <(tr -d '\r' < "$MANIFEST") <(table_counts "$TARGET") > /dev/null; then
    echo "   OK : $(wc -l < "$MANIFEST" | tr -d ' ') tables, $(awk -F'|' '{s+=$2} END {print s}' "$MANIFEST") lignes, identiques au manifeste."
else
    echo "   ÉCART (manifeste < > base restaurée) :"
    diff <(tr -d '\r' < "$MANIFEST") <(table_counts "$TARGET") | sed 's/^/     /' || true
    errors=$((errors + 1))
fi

echo "2. Déclencheurs « ajout seul »"
for trigger in audit_logs_no_update_delete ledger_entries_no_update_delete results_no_update_delete settlements_no_update_delete; do
    if [ "$(sql "$TARGET" "select count(*) from pg_trigger where tgname = '$trigger'" | tr -d '\r')" = "1" ]; then
        echo "   OK : $trigger"
    else
        echo "   MANQUANT : $trigger"
        errors=$((errors + 1))
    fi
done

echo "3. Cohérence du ledger"
broken=$(sql "$TARGET" "select count(*) from (
    select w.id, coalesce(sum(e.amount_minor), 0) as total,
           (select balance_after_minor from ledger_entries l where l.wallet_id = w.id order by l.id desc limit 1) as last_balance
    from wallets w left join ledger_entries e on e.wallet_id = w.id group by w.id
) s where s.last_balance is not null and s.last_balance <> s.total" | tr -d '\r')
if [ "$broken" = "0" ]; then
    echo "   OK : $(sql "$TARGET" "select count(*) from wallets" | tr -d '\r') portefeuilles, soldes égaux à la somme des écritures."
else
    echo "   INCOHÉRENT : $broken portefeuille(s)"
    errors=$((errors + 1))
fi

[ "$errors" -eq 0 ] || fail "$errors contrôle(s) en échec."
echo "Restauration vérifiée."
