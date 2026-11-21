#!/bin/sh
# Revue P8-02, F9 (V2-C07) : compte de l'APPLICATION dans le conteneur PostgreSQL de Production
# (docker-compose.local-db.yml seulement). Exécuté par l'image postgres à la PREMIÈRE initialisation du volume.
#
# Le conteneur est créé avec le compte propriétaire (DB_MIGRATION_USERNAME de .env.migration) ; ce script ajoute le
# compte de l'application (DB_USERNAME et DB_PASSWORD de .env), propriétaire de rien. Ses droits sur les tables sont
# réglés après chaque migration par scripts/db/restrict-app-role.sql, comme avec la base managée.
set -eu

: "${APP_DB_USERNAME:?APP_DB_USERNAME manquant}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD manquant}"

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -v app="$APP_DB_USERNAME" -v pw="$APP_DB_PASSWORD" -v db="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD :'pw';
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO :"app";
SQL
