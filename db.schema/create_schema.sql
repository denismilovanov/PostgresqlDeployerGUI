BEGIN;

----------------------------------------------------------------------------
-- create schema to store deployer objects

CREATE OR REPLACE FUNCTION __temp1() RETURNS varchar AS
$BODY$
BEGIN

    BEGIN
        CREATE SCHEMA postgresql_deployer;
        RETURN 'schema postgresql_deployer created.';
    EXCEPTION WHEN others THEN NULL; END;

    RETURN 'schema postgresql_deployer already exists.';

END
$BODY$ LANGUAGE plpgsql VOLATILE;

SELECT __temp1();

DROP FUNCTION __temp1();

----------------------------------------------------------------------------
-- types of migrated objects ...

CREATE TABLE IF NOT EXISTS postgresql_deployer.migrations_objects (
    id integer PRIMARY KEY,
    index varchar(32) NOT NULL,
    rank integer NOT NULL,
    params json NOT NULL
);

DELETE FROM postgresql_deployer.migrations_objects;

-- are:
INSERT INTO postgresql_deployer.migrations_objects
    VALUES
        (1, 'tables', 3, '{"is_forwardable":true}'),
        (2, 'seeds', 4, '{"is_forwardable":false}'),
        (3, 'types', 5, '{"is_forwardable":false}'),
        (4, 'functions', 6, '{"is_forwardable":false}'),
        (5, 'sequences', 2, '{"is_forwardable":true}'),
        (6, 'queries_before', 1, '{"is_forwardable":true}'),
        (7, 'queries_after', 100, '{"is_forwardable":true}'),
        (8, 'triggers', 7, '{"is_forwardable":false}'),
        (9, 'views', 8, '{"is_forwardable":false}');

----------------------------------------------------------------------------
-- actual information about objects deployed

CREATE TABLE IF NOT EXISTS postgresql_deployer.migrations (
    schema_name varchar(255) NOT NULL,
    type_id integer NOT NULL REFERENCES postgresql_deployer.migrations_objects (id)
        DEFERRABLE INITIALLY DEFERRED,
    object_name varchar(255) NOT NULL,
    hash varchar(32) NOT NULL,
    content text NOT NULL,
    CONSTRAINT migrations_pkey PRIMARY KEY (schema_name, type_id, object_name)
);

----------------------------------------------------------------------------
-- drops all functions within schema with given name

CREATE OR REPLACE FUNCTION postgresql_deployer.drop_all_functions_by_name(
    f_function_name varchar,
    s_schema_name varchar
)
    RETURNS void AS
$BODY$
DECLARE
    s_sql varchar;
BEGIN

    FOR s_sql IN SELECT 'DROP FUNCTION ' || n.nspname  || '.' || p.proname
                || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ');'

        FROM pg_catalog.pg_proc AS p

        LEFT JOIN pg_catalog.pg_namespace n ON
            n.oid = p.pronamespace

        WHERE   p.proname = f_function_name AND
                n.nspname = s_schema_name

    LOOP

        IF s_sql IS NOT NULL THEN
            EXECUTE s_sql;
        END IF;

    END LOOP;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- abstract database object

DROP TYPE IF EXISTS postgresql_deployer.database_object CASCADE;

CREATE TYPE postgresql_deployer.database_object AS (
    database_name varchar,
    schema_name varchar,
    object_index varchar,
    object_name varchar,
    additional_sql text
);

----------------------------------------------------------------------------
-- drops type with all dependencies

CREATE OR REPLACE FUNCTION postgresql_deployer.drop_type_with_dependent_functions(
    s_database_name varchar,
    s_schema_name varchar,
    s_type_name varchar
)
    RETURNS SETOF postgresql_deployer.database_object AS
$BODY$
DECLARE
    r_func postgresql_deployer.database_object;
BEGIN

    FOR r_func IN SELECT *
                    FROM postgresql_deployer.get_type_dependent_functions(s_database_name, s_schema_name, s_type_name)

    LOOP

        IF r_func.additional_sql IS NOT NULL THEN
            r_func.additional_sql := 'DROP FUNCTION ' || r_func.additional_sql;
            EXECUTE r_func.additional_sql;
            RETURN NEXT r_func;
        END IF;

    END LOOP;

    -- drop type, all dependencies were dropped above
    EXECUTE 'DROP TYPE IF EXISTS ' || s_schema_name || '.' || s_type_name || ' ;';

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- get types dependencies

CREATE OR REPLACE FUNCTION postgresql_deployer.get_type_dependent_functions(
    s_database_name varchar,
    s_schema_name varchar,
    s_type_name varchar
)
    RETURNS SETOF postgresql_deployer.database_object AS
$BODY$
DECLARE
    r_func postgresql_deployer.database_object;
    i_type_id integer;
    i_type_array_id integer;
BEGIN

    -- oid of type
    SELECT reltype INTO i_type_id
        FROM pg_class
        WHERE   relname = s_type_name AND
                relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- oid of type[]
    SELECT typarray INTO i_type_array_id
        FROM pg_type
        WHERE   typname = s_type_name AND
                typnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- to prevent NULL in array, it breaks &&, see below
    i_type_id := coalesce(i_type_id, 0);
    i_type_array_id := coalesce(i_type_array_id, 0);

    -- search for functions
    FOR r_func IN SELECT    s_database_name,
                            n.nspname::varchar AS schema_name,
                            'functions' AS object_type,
                            p.proname::varchar AS function_name,
                            n.nspname  || '.' || p.proname
                                || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ');' AS additional_sql

        FROM pg_catalog.pg_proc AS p

        LEFT JOIN pg_catalog.pg_namespace n ON
            n.oid = p.pronamespace

        WHERE   prorettype IN (i_type_id, i_type_array_id) OR
                proargtypes::int[] && array[i_type_id, i_type_array_id] OR
                prosrc ~ ('DECLARE.+?' || s_schema_name || '\.' || s_type_name || '(;|\[).+?BEGIN')

    LOOP

        IF r_func.additional_sql IS NOT NULL THEN
            RETURN NEXT r_func;
        END IF;

    END LOOP;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- get tables dependencies

CREATE OR REPLACE FUNCTION postgresql_deployer.get_table_dependent_functions(
    s_database_name varchar,
    s_schema_name varchar,
    s_table_name varchar
)
    RETURNS SETOF postgresql_deployer.database_object AS
$BODY$
DECLARE
    r_func postgresql_deployer.database_object;
    i_table_id integer;
    i_table_array_id integer;
BEGIN

    -- oid of table
    SELECT reltype INTO i_table_id
        FROM pg_class
        WHERE   relname = s_table_name AND
                relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- oid of table[]
    SELECT typarray INTO i_table_array_id
        FROM pg_type
        WHERE   typname = s_table_name AND
                typnamespace = (SELECT oid FROM pg_namespace WHERE nspname = s_schema_name);

    -- to prevent NULL in array, it breaks &&, see below
    i_table_id := coalesce(i_table_id, 0);
    i_table_array_id := coalesce(i_table_array_id, 0);

    -- search for functions
    FOR r_func IN SELECT    s_database_name,
                            n.nspname::varchar AS schema_name,
                            'functions' AS object_table,
                            p.proname::varchar AS function_name,
                            n.nspname  || '.' || p.proname
                                || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ');' AS additional_sql

        FROM pg_catalog.pg_proc AS p

        LEFT JOIN pg_catalog.pg_namespace n ON
            n.oid = p.pronamespace

        WHERE   prorettype IN (i_table_id, i_table_array_id) OR
                proargtypes::int[] && array[i_table_id, i_table_array_id] OR
                prosrc ~ ('DECLARE.+?' || s_schema_name || '\.' || s_table_name || '(;|\[).+?BEGIN')

    LOOP

        IF r_func.additional_sql IS NOT NULL THEN
            RETURN NEXT r_func;
        END IF;

    END LOOP;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- updates or inserts migration record

CREATE OR REPLACE FUNCTION postgresql_deployer.upsert_migration(
    i_user_id bigint,
    s_commit_hash varchar,
    s_schema_name varchar,
    i_type_id integer,
    s_object_name varchar,
    s_hash varchar,
    t_content text
)
    RETURNS void AS
$BODY$
DECLARE

BEGIN

    -- log
    INSERT INTO postgresql_deployer.migration_log
        (user_id, schema_name, type_id, object_name, commit_hash)
        VALUES (
            i_user_id,
            s_schema_name,
            i_type_id,
            s_object_name,
            s_commit_hash
        );

    -- upsert
    BEGIN

        INSERT INTO postgresql_deployer.migrations
            SELECT  s_schema_name,
                    i_type_id,
                    s_object_name,
                    s_hash,
                    t_content;

    EXCEPTION WHEN unique_violation THEN

        UPDATE postgresql_deployer.migrations
            SET hash = s_hash,
                content = t_content
            WHERE   schema_name = s_schema_name AND
                    type_id = i_type_id AND
                    object_name = s_object_name;

    END;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- table of users

CREATE TABLE IF NOT EXISTS postgresql_deployer.users (
    id bigserial PRIMARY KEY,
    name varchar(255) NOT NULL UNIQUE,
    email varchar(255) NOT NULL UNIQUE,
    password_enc varchar(64),
    salt varchar(32),
    cookie varchar(32) UNIQUE,
    last_seen_at timestamp without time zone NULL
);

----------------------------------------------------------------------------
-- new user addition

CREATE OR REPLACE FUNCTION postgresql_deployer.add_user(
    s_name varchar,
    s_email varchar,
    s_password varchar
)
    RETURNS bigint AS
$BODY$
DECLARE
    s_salt varchar;
    i_user_id bigint;
BEGIN

    s_salt := md5(now()::varchar || s_email);

    BEGIN

        INSERT INTO postgresql_deployer.users
            (name, email, password_enc, salt)
            VALUES
            (s_name, s_email, md5(s_password || s_salt), s_salt)
            RETURNING id INTO i_user_id;

    EXCEPTION WHEN unique_violation THEN
        RETURN 0;
    END;

    RETURN i_user_id;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- login

CREATE OR REPLACE FUNCTION postgresql_deployer.login_user(
    s_email varchar,
    s_password_given varchar
)
    RETURNS postgresql_deployer.users AS
$BODY$
DECLARE
    r_record postgresql_deployer.users;
BEGIN

    UPDATE postgresql_deployer.users
        SET cookie = md5(now()::varchar || password_enc),
            last_seen_at = now()
        WHERE   email = s_email AND
                md5(s_password_given || salt) = password_enc
        RETURNING * INTO r_record;

    RETURN r_record;

END
$BODY$
    LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- authorize

CREATE OR REPLACE FUNCTION postgresql_deployer.authorize_user(
    s_cookie varchar
)
    RETURNS postgresql_deployer.users AS
$BODY$
DECLARE
    r_record postgresql_deployer.users;
BEGIN

    SELECT * INTO r_record
        FROM postgresql_deployer.users
        WHERE   cookie = s_cookie AND
                last_seen_at >= now() - interval '1 day';

    RETURN r_record;

END
$BODY$
    LANGUAGE plpgsql STABLE;


----------------------------------------------------------------------------
-- log

CREATE TABLE IF NOT EXISTS postgresql_deployer.migration_log (
    id bigserial PRIMARY KEY,
    user_id bigint REFERENCES postgresql_deployer.users (id),
    schema_name varchar(255) NOT NULL,
    type_id integer NOT NULL REFERENCES postgresql_deployer.migrations_objects (id)
        DEFERRABLE INITIALLY DEFERRED,
    object_name varchar(255) NOT NULL,
    commit_hash varchar(40) NOT NULL,
    deployed_at timestamp without time zone NOT NULL DEFAULT now()
);

----------------------------------------------------------------------------
-- create test function for plpgsql_check

CREATE OR REPLACE FUNCTION postgresql_deployer.test_plpgsql_check_function() RETURNS void AS
$BODY$
BEGIN

    SELECT * FROM postgresql_deployer.non_existing;

END
$BODY$ LANGUAGE plpgsql STABLE;

----------------------------------------------------------------------------
-- test for plpgsql_check

CREATE OR REPLACE FUNCTION postgresql_deployer.test_plpgsql_check_extension() RETURNS text AS
$BODY$
DECLARE
    o_oid oid;
BEGIN

    SELECT oid INTO o_oid
        FROM pg_proc
        WHERE proname = 'test_plpgsql_check_function';

    IF NOT FOUND THEN
        RETURN 'There is no postgresql_deployer.test_plpgsql_check_function function';
    END IF;

    -- perform function check
    BEGIN
        PERFORM plpgsql_check_function(o_oid);
    EXCEPTION WHEN others THEN
        -- extension fails
        RETURN 'plpgsql_check_function is NOT ready';
    END;

    RETURN 'plpgsql_check_function is ready';

END
$BODY$ LANGUAGE plpgsql VOLATILE;

----------------------------------------------------------------------------
-- that's all

SELECT 'type "COMMIT".';
SELECT 'then "SELECT postgresql_deployer.add_user(name, email, pass);" to create user.';
SELECT postgresql_deployer.test_plpgsql_check_extension();

