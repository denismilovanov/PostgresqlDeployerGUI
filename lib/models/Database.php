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

}


