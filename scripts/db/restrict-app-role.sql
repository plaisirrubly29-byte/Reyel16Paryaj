-- Revue P8-02, F9 (V2-C07) : droits du compte de l'APPLICATION sur la base de Production.
--
-- Lancé par scripts/deploy/production-deploy.sh après chaque migration, avec le compte PROPRIÉTAIRE des tables
-- (celui de .env.migration), jamais avec celui de l'application :
--
--   psql -d reyel16 -v ON_ERROR_STOP=1 -v app_role=reyel16 -f scripts/db/restrict-app-role.sql
--
-- Effet (idempotent, relancé à chaque déploiement : les tables créées par une migration sont couvertes) :
-- - lecture et écriture sur les tables ordinaires ;
-- - tables en ajout seul (déclencheur appelant une fonction *_append_only) : SELECT et INSERT seulement ;
-- - TRUNCATE, REFERENCES et TRIGGER retirés partout.
-- Puis contrôle : le script échoue si le compte de l'application peut encore modifier, effacer ou vider une table
-- en ajout seul, ou retirer un déclencheur (propriétaire d'une table ou de la base, membre du compte propriétaire,
-- superutilisateur, droit CREATE sur le schéma).

\set ON_ERROR_STOP on

SELECT set_config('reyel16.app_role', :'app_role', false) AS app_role \gset

DO $$
DECLARE
    app text := current_setting('reyel16.app_role');
    -- Tables en ajout seul connues (P8-02) : le script échoue si l'une d'elles a perdu son déclencheur.
    expected text[] := ARRAY['audit_logs', 'ledger_entries', 'results', 'settlements', 'setting_versions'];
    append_only text[];
    missing text[];
    tbl text;
BEGIN
    IF app IS NULL OR app = '' THEN
        RAISE EXCEPTION 'Variable app_role manquante (compte de l''application).';
    END IF;
    IF app = current_user THEN
        RAISE EXCEPTION 'Lancé avec le compte de l''application (%) : utiliser le compte propriétaire (.env.migration).', app;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = app) THEN
        RAISE EXCEPTION 'Le compte « % » n''existe pas (scripts/db/production-roles.sql).', app;
    END IF;

    -- Tables protégées par un déclencheur « ajout seul » (convention des migrations : fonction *_append_only).
    SELECT coalesce(array_agg(DISTINCT c.relname::text ORDER BY c.relname::text), ARRAY[]::text[]) INTO append_only
    FROM pg_trigger tg
    JOIN pg_class c ON c.oid = tg.tgrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_proc p ON p.oid = tg.tgfoid
    WHERE n.nspname = 'public' AND NOT tg.tgisinternal AND p.proname LIKE '%\_append\_only';

    missing := ARRAY(SELECT t FROM unnest(expected) AS t WHERE NOT t = ANY (append_only));
    IF cardinality(missing) > 0 THEN
        RAISE EXCEPTION 'Tables en ajout seul sans déclencheur : % (migrations lancées ?)', missing;
    END IF;

    -- Contrôles AVANT tout changement : ce que le compte de l'application ne doit jamais pouvoir faire.
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = app AND (rolsuper OR rolcreaterole OR rolcreatedb OR rolbypassrls)) THEN
        RAISE EXCEPTION 'Le compte « % » est superutilisateur ou peut créer des comptes ou des bases.', app;
    END IF;
    IF pg_has_role(app, current_user, 'MEMBER') THEN
        RAISE EXCEPTION 'Le compte « % » est membre du compte propriétaire « % ».', app, current_user;
    END IF;
    IF (SELECT pg_get_userbyid(datdba)::text FROM pg_database WHERE datname = current_database()) = app THEN
        RAISE EXCEPTION 'Le compte « % » est propriétaire de la base : il pourrait retirer les déclencheurs.', app;
    END IF;
    IF has_schema_privilege(app, 'public', 'CREATE') THEN
        RAISE EXCEPTION 'Le compte « % » peut créer des objets dans le schéma public.', app;
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND pg_has_role(app, c.relowner, 'USAGE')
    ) THEN
        RAISE EXCEPTION 'Le compte « % » est propriétaire d''objets du schéma public : il pourrait retirer les déclencheurs.', app;
    END IF;

    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', app);
    EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I', app);
    EXECUTE format('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO %I', app);
    EXECUTE format('REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM %I', app);

    FOREACH tbl IN ARRAY append_only LOOP
        EXECUTE format('REVOKE UPDATE, DELETE, TRUNCATE ON public.%I FROM %I', tbl, app);
    END LOOP;

    -- Contrôle final : plus aucun droit de modification sur les tables en ajout seul.
    FOREACH tbl IN ARRAY append_only LOOP
        IF has_table_privilege(app, format('public.%I', tbl), 'UPDATE')
            OR has_table_privilege(app, format('public.%I', tbl), 'DELETE')
            OR has_table_privilege(app, format('public.%I', tbl), 'TRUNCATE') THEN
            RAISE EXCEPTION 'Le compte « % » peut encore modifier, effacer ou vider « % ».', app, tbl;
        END IF;
    END LOOP;

    RAISE NOTICE 'Compte « % » restreint : % tables en ajout seul (%) en lecture et ajout seulement.',
        app, cardinality(append_only), array_to_string(append_only, ', ');
END
$$;
