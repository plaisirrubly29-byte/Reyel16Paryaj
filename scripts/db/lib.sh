# Fonctions communes aux scripts de sauvegarde (P8-04). À inclure avec : . "$(dirname "$0")/lib.sh"
#
# Deux modes :
# - local (par défaut) : outils PostgreSQL du conteneur « postgres » de docker-compose.yml ;
# - serveur : si PGHOST est défini, outils installés sur la machine et variables PG* standard
#   (PGHOST, PGPORT, PGUSER, PGPASSWORD ou ~/.pgpass). Ex. staging ou base managée.

set -euo pipefail

: "${PGUSER:=reyel16}"

# Exécute une commande PostgreSQL (pg_dump, pg_restore, psql, createdb) ; l'entrée et la sortie standard passent.
pg() {
    if [ -n "${PGHOST:-}" ]; then
        "$@"
    else
        docker compose exec -T postgres "$@"
    fi
}

# Requête SQL sur une base, résultat brut (sans en-tête).
sql() {
    local db="$1" query="$2"
    pg psql -U "$PGUSER" -d "$db" -v ON_ERROR_STOP=1 -Atc "$query"
}

# Nombre exact de lignes de chaque table du schéma public : « table|nombre », trié.
table_counts() {
    sql "$1" "select table_name, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', table_name), false, true, '')))[1]::text::bigint
              from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by 1" | tr -d '\r'
}

db_exists() {
    [ "$(sql postgres "select count(*) from pg_database where datname = '$1'" | tr -d '\r')" = "1" ]
}

now_seconds() { date +%s; }

fail() { echo "ERREUR : $*" >&2; exit 1; }
