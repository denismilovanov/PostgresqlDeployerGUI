----------------------------------------------------------------------------
-- this script contains:
-- 1) triggers support


BEGIN;

DELETE FROM postgresql_deployer.migrations_objects
    WHERE id = 8;

INSERT INTO postgresql_deployer.migrations_objects
    SELECT  8, 'triggers', 7, '{"is_forwardable":false}';

COMMIT;

