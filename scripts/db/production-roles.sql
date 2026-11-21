-- Revue P8-02, F9 (V2-C07) : comptes de la base PostgreSQL MANAGÉE de Production. À lancer UNE SEULE FOIS, avant le
-- premier déploiement, avec le compte d'administration fourni par l'hébergeur, depuis le serveur applicatif :
--
--   psql "host=<hôte privé> port=5432 dbname=postgres user=<admin> sslmode=require" -f scripts/db/production-roles.sql
--
-- psql demande alors les deux mots de passe (\password : envoyés chiffrés, jamais écrits dans un journal), à générer
-- avec « openssl rand -hex 32 » et à reporter sur le serveur :
--   reyel16_owner → .env.migration (DB_MIGRATION_USERNAME, DB_MIGRATION_PASSWORD)
--   reyel16       → .env           (DB_USERNAME, DB_PASSWORD)
--
-- Deux comptes :
-- - reyel16_owner : propriétaire de la base et des tables ; migrations, rôles admin et sauvegardes, PENDANT le
--   déploiement seulement. Son mot de passe n'est jamais donné à l'application en marche.
-- - reyel16 : l'application. Propriétaire de rien ; ses droits sont réglés après chaque migration par
--   scripts/db/restrict-app-role.sql (tables en ajout seul : lecture et ajout seulement).
-- Voir docs/ops/deploiement-production.md §3.1.

\set ON_ERROR_STOP on

CREATE ROLE reyel16_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE reyel16 LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;

\echo 'Mot de passe de reyel16_owner (compte de migration, .env.migration) :'
\password reyel16_owner
\echo 'Mot de passe de reyel16 (compte de l''application, .env) :'
\password reyel16

-- PostgreSQL 16 : donner une base à reyel16_owner exige que l'administrateur puisse en prendre le rôle.
GRANT reyel16_owner TO CURRENT_USER;

-- PostgreSQL 15 et plus : le schéma public appartient au propriétaire de la base, et personne d'autre n'y crée d'objet.
CREATE DATABASE reyel16 OWNER reyel16_owner;
REVOKE ALL ON DATABASE reyel16 FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE reyel16 TO reyel16;

\echo 'Comptes créés. Suite : .env et .env.migration sur le serveur, puis ./production-deploy.sh check.'
