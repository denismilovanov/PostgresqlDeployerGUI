<?php

class Database
{
    public $sDatabaseName;

    public static $oDB;

    public static function getObjectsAsVirtualFiles($sSchema, $sObjectIndex)
    {
        if ($sObjectIndex == 'tables') {

            return self::$oDB->selectIndexedTable("
                SELECT  tablename,
                        tablename AS file,
                        '' AS hash -- there is no file therefore there is no hash
                    FROM pg_tables
                    WHERE schemaname = ?w
                    ORDER BY tablename;
            ",
                $sSchema
            );

        } else if ($sObjectIndex == 'sequences') {

            return self::$oDB->selectIndexedTable("
                SELECT  c.relname,
                        c.relname AS file,
                        '' AS hash
                    FROM pg_class AS c
                    INNER JOIN pg_catalog.pg_namespace n ON
                        n.oid = c.relnamespace
                    WHERE   n.nspname = ?w AND
                            c.relkind = 'S';
            ",
                $sSchema
            );

        } else if ($sObjectIndex == 'queries_before' or $sObjectIndex == 'queries_after') {

            return self::$oDB->selectIndexedTable("
                SELECT  object_name,
                        object_name AS file,
                        '' AS hash
                    FROM postgresql_deployer.migrations
                    WHERE   schema_name = ?w AND
                            type_id = ?d;
            ",
                $sSchema,
                $sObjectIndex == 'queries_before' ? 6 : 7
            );

        } else if ($sObjectIndex == 'functions') {

            return self::$oDB->selectIndexedTable("
                SELECT p.proname, p.proname AS file, '' AS hash
                    FROM pg_catalog.pg_proc AS p
                    INNER JOIN pg_catalog.pg_namespace n ON
                        n.oid = p.pronamespace
                    WHERE   n.nspname = ?w AND
                            p.prolang > 16  -- non-system languages (plpgsql)
                    ORDER BY p.proname;
            ",
                $sSchema
            );

        } else if ($sObjectIndex == 'types') {

            return self::$oDB->selectIndexedTable("
                SELECT t.typname, t.typname AS file, '' AS hash
                    FROM pg_catalog.pg_type t
                    INNER JOIN pg_catalog.pg_namespace n
                        ON n.oid = t.typnamespace
                    WHERE   n.nspname = ?w AND
                            -- look for composite ('c') types only:
                            (--t.typrelid = 0 OR -- for non user types such as hstore
                                (SELECT c.relkind = 'c'
                                    FROM pg_catalog.pg_class c
                                    WHERE c.oid = t.typrelid)
                            ) AND
                            -- exclude type[]
                            NOT EXISTS(
                                SELECT 1
                                    FROM pg_catalog.pg_type el
                                    WHERE   el.oid = t.typelem AND
                                            el.typarray = t.oid
                            )
                    ORDER BY t.typname;
            ",
                $sSchema
            );

        } else if ($sObjectIndex == 'triggers') {

            return self::$oDB->selectIndexedTable("
                SELECT c.relname || '.' || t.tgname, c.relname || '.' || t.tgname || '.sql' AS file, '' AS hash
                    FROM pg_catalog.pg_trigger AS t
                    INNER JOIN pg_catalog.pg_class AS c
                        ON t.tgrelid = c.oid
                    INNER JOIN pg_catalog.pg_namespace n
                        ON n.oid = c.relnamespace
                    WHERE   n.nspname = ?w AND
                            NOT t.tgisinternal
                    ORDER BY t.tgname;
            ",
                $sSchema
            );

        }

        return array();

    }

    public static function getSchemas()
    {
        return self::$oDB->selectIndexedColumn("
            SELECT nspname, nspname
                FROM pg_namespace
                WHERE   nspname !~ '^pg_' AND
                        nspname NOT IN ('information_schema', 'postgresql_deployer')
                ORDER BY nspname;
        ");
    }

    public static function getPlpgsqlCheckStatus()
    {
        if (! DBRepository::getSettingValue('plpgsql_check.active')) {
            return array();
        }

        return array(
            self::$oDB->selectField("
                SELECT postgresql_deployer.test_plpgsql_check_extension();
            "),
        );
    }

    public static function checkAllStoredFunctionsByPlpgsqlCheck()
    {
        $sExcludeRegexp = DBRepository::getSettingValue('plpgsql_check.exclude_regexp', '');

        if (! $sExcludeRegexp) {
            $sExcludeRegexp = ''; // empty string will mean that there is no filter
        }

        // check all nontrigger functions
        $aResult = self::$oDB->selectRecord("
            WITH data AS (
                SELECT  n.nspname AS schema_name,
                        p.proname AS object_name,
                        (SELECT array_to_string(array(SELECT * FROM plpgsql_check_function(p.oid)), E'\n')) AS comment
                    FROM pg_catalog.pg_namespace n
                    JOIN pg_catalog.pg_proc AS p
                        ON pronamespace = n.oid
                    JOIN pg_catalog.pg_language AS l
                        ON p.prolang = l.oid
                    WHERE   l.lanname = 'plpgsql' AND
                            p.prorettype <> 2279 AND
                            p.proname NOT IN ('test_plpgsql_check_extension', 'test_plpgsql_check_function') AND
                            NOT p.prosrc ~ 'EXECUTE' AND    -- ''don't use record variable as target for dynamic queries or
                                                            --   disable plpgsql_check for functions that use dynamic queries.''
                                                            -- we skip ANY 'execute'-containing function
                            CASE WHEN ?w != ''
                                THEN NOT (n.nspname || '.' || p.proname) ~ ?w -- does not match exclude filter
                                ELSE TRUE -- filter is not set
                            END
            )
            SELECT *
                FROM data
                WHERE comment != ''
                ORDER BY schema_name, object_name
                LIMIT 1; -- one bad function is enough
        ",
            $sExcludeRegexp, $sExcludeRegexp
        );

        // do we have at least one bad function?
        if ($aResult) {
            // make object for it
            $oFunction = DatabaseObject::make(
                DBRepository::getCurrentDatabase(),
                $aResult['schema_name'],
                'functions',
                $aResult['object_name'],
                ''
            );
            DBRepository::setLastAppliedObject($oFunction);
            // break main transaction
            self::$oDB->rollback();
            //
            throw new Exception($aResult['comment']);
        }
    }

}


