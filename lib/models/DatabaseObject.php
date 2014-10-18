<?php

abstract class DatabaseObject
{
    public $sDatabaseName, $sSchemaName, $sObjectIndex, $sObjectName, $sObjectContent, $sObjectContentHash;

    public static $oDB;
    public static $aMigrations;
    public static $sCommitHash;
    public static $iCurrentUserId;
    public static $bImitate;

    private static function getObjectsIndexes()
    {
        return array('tables', 'types', 'seeds', 'functions');
    }

    public static function readMigrations()
    {
        self::$aMigrations = self::$oDB->select3IndexedColumn("
            SELECT  schema_name,
                    (SELECT index
                        FROM postgresql_deployer.migrations_objects AS o
                        WHERE o.id = type_id) AS type_index,
                    object_name,
                    hash
                FROM postgresql_deployer.migrations
                ORDER BY schema_name, type_id, object_name;
        ");
    }

    protected static function stripTransaction($sQuery)
    {
        $sQuery = str_replace("BEGIN;", "", $sQuery);
        $sQuery = str_replace("COMMIT;", "", $sQuery);

        return trim($sQuery);
    }

    public static function getHash($sObjectContent)
    {
        return md5($sObjectContent);
    }

    public static function make($sDatabaseName, $sSchemaName, $sObjectIndex, $sObjectName, $sObjectContent)
    {
        $aClassesNames = array(
            'tables' => 'Table',
            'seeds' => 'Seed',
            'types' => 'Type',
            'functions' => 'StoredFunction',
        );

        $aObject = new $aClassesNames[$sObjectIndex];

        $aObject->sDatabaseName = $sDatabaseName;
        $aObject->sSchemaName = $sSchemaName;
        $aObject->sObjectIndex = $sObjectIndex;
        $aObject->sObjectName = $sObjectName;
        $aObject->sObjectContent = $sObjectContent;
        $aObject->sObjectContentHash = self::getHash($sObjectContent);

        return $aObject;
    }

    public function getObjectContentInDatabase()
    {
        return self::$oDB->selectField("
            SELECT content
                FROM postgresql_deployer.migrations
                WHERE   schema_name = ?w AND
                        type_id = (SELECT id FROM postgresql_deployer.migrations_objects WHERE index = ?w) AND
                        object_name = ?w
        ",
            $this->sSchemaName,
            $this->sObjectIndex,
            $this->sObjectName
        );
    }

    public function upsertMigration()
    {
        self::$oDB->query("
            SELECT postgresql_deployer.upsert_migration(
                ?d,
                ?w,
                ?w,
                (SELECT id FROM postgresql_deployer.migrations_objects WHERE index = ?w),
                ?w,
                ?w,
                ?w
            );
        ",
            self::$iCurrentUserId,
            self::$sCommitHash,
            $this->sSchemaName,
            $this->sObjectIndex,
            $this->sObjectName,
            $this->sObjectContentHash,
            $this->sObjectContent
        );
    }

    abstract public function hasChanged($sCurrentHash);

    abstract public function signatureChanged();

    abstract public function objectExists();

    abstract public function getObjectDependencies();

    abstract public function applyObject();

    abstract public function describe();

}


