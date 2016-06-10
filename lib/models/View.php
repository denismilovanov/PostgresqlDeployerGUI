<?php

class View extends DatabaseObject
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM pg_views
                WHERE   schemaname = ?w AND
                        viewname = ?w
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

    public function isDescribable ()
    {
        return true;
    }

    public function isDefinable ()
    {
        return true;
    }

    public function isDroppable ()
    {
        return true;
    }

    public function define()
    {
        $sError = '';
        $sOutput = DBRepository::callExternalTool(
            'pg_dump',
            array(
                '--schema-only',
                '-t',
                $this->sSchemaName . '.' . $this->sObjectName
            ),
            $sError
        );

        $sOutput = preg_replace("~^--.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~^SET.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);

        // alter table works too
        $sOutput = preg_replace("~ALTER\sTABLE~uixs", "ALTER VIEW", $sOutput);

        // change schema (pg_dump sets it through search_path)
        $sOutput = preg_replace("~ALTER\sVIEW\s(.+?)~uixs", "ALTER VIEW " . $this->sSchemaName . ".$1", $sOutput);
        $sOutput = preg_replace("~CREATE\sVIEW\s(.+?)~uixs", "CREATE VIEW " . $this->sSchemaName . ".$1", $sOutput);

        $sOutput = trim($sOutput);

        return array(
            'definition' => $sOutput,
            'error' => $sError,
        );
    }

    public function describe()
    {
        $sError = '';
        $sOutput = DBRepository::callExternalTool(
            'psql',
            array('-c\d+ ' . $this->sSchemaName . '.' . $this->sObjectName),
            $sError
        );

        return array(
            'description' => $sOutput,
            'error' => $sError,
        );
    }

    public function drop()
    {
        if ($this->objectExists()) {
            self::$oDB->t()->query("
                DROP VIEW ?.? CASCADE;
            ",
                $this->sSchemaName,
                $this->sObjectName
            );
        } else {
            throw new Exception("There is no view.");
        }

        return true;
    }

}


