----------------------------------------------------------------------------
-- this script contains:
-- 1) views support


BEGIN;

DELETE FROM postgresql_deployer.migrations_objects
    WHERE id = 9;

INSERT INTO postgresql_deployer.migrations_objects
    SELECT  9, 'views', 7, '{"is_forwardable":false}';

COMMIT;

