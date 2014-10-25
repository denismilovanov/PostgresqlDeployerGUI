<?php

class Table extends DatabaseObject
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
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash;
    }

    public function define()
    {
        $sOutput = DBRepository::callExternalTool(
            'pg_dump',
            array(
                '--schema-only',
                '-t',
                $this->sSchemaName . '.' . $this->sObjectName
            )
        );

        $sOutput = preg_replace("~^--.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~^SET.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);

        $sOutput = trim($sOutput);

        return $sOutput;
    }

    public function describe()
    {
        $sOutput = DBRepository::callExternalTool(
            'psql',
            array('-c\d+ ' . $this->sSchemaName . '.' . $this->sObjectName)
        );

        return $sOutput;
    }

    public function drop()
    {
        if ($this->objectExists()) {
            self::$oDB->t()->query("
                DROP TABLE ?.?
            ",
                // this variables were checked in objectExists so we can drop nothing but pointed table
                $this->sSchemaName,
                $this->sObjectName
            );
        } else {
            throw new Exception("There is no table.");
        }

        return true;
    }

}


