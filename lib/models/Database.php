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
            ",
                $sSchema
            );

        }

        return array();

    }

}


