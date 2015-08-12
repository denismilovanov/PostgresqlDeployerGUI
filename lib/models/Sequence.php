<?php

class Sequence extends DatabaseObject implements IForwardable
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM pg_class AS c
                INNER JOIN pg_catalog.pg_namespace n ON
                    n.oid = c.relnamespace
                WHERE   n.nspname = ?w AND
                        c.relname = ?w AND
                        c.relkind = 'S';
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

    public function canBeForwarded()
    {
        return $this->oDiff->canBeForwarded();
    }

    public function forward()
    {
        if (self::$bImitate) {
            return;
        }

        DBRepository::setLastAppliedObject($this);

        $sForwardStatements = $this->getDiff()->getForwardStatements("\n");

        $sForwardStatements = self::stripTransaction($sForwardStatements);

        if ($sForwardStatements) {
            self::$oDB->query($sForwardStatements);
        }
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
        $sOutput = preg_replace("~ALTER\sTABLE~uixs", "ALTER SEQUENCE", $sOutput);

        // change schema (pg_dump sets it through search_path)
        $sOutput = preg_replace("~CREATE\s(SEQUENCE)\s(.+?)~uixs", "CREATE $1 " . $this->sSchemaName . ".$2", $sOutput);
        $sOutput = preg_replace("~ALTER\s(SEQUENCE)\s(.+?)~uixs", "ALTER $1 " . $this->sSchemaName . ".$2", $sOutput);

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
                DROP SEQUENCE ?.? CASCADE;
            ",
                // this variables were checked in objectExists so we can drop nothing but pointed sequence
                $this->sSchemaName,
                $this->sObjectName
            );
        } else {
            throw new Exception("There is no sequence.");
        }

        return true;
    }

}


