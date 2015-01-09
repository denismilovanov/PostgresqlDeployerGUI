<?php

class Seed extends DatabaseObject
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM pg_tables
                WHERE   schemaname = ?w AND
                        tablename = ?w
        ",
            $this->sSchemaName,
            $this->sObjectName
        );
    }

    public function getObjectDependencies()
    {
        return array();
    }

    public function signatureChanged()
    {
        return false;
    }

    public function applyObject()
    {
        if (self::$bImitate) {
            return;
        }

        DBRepository::setLastAppliedObject($this);

        self::$oDB->query(self::stripTransaction($this->sObjectContent));
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash;
    }

    public function define()
    {
        return array(
            'definition' => '',
            'error' => '',
        );
    }

    public function describe()
    {
        $aDefinition = $this->define();
        return array(
            'description' => $aDefinition['definition'],
            'error' => '',
        );
    }

    public function drop()
    {
        return true;
    }
}


