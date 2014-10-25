<?php

// for external `pg_dump`
use Symfony\Component\Process\ProcessBuilder;

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
        // to get server version and connection params
        $aCredentials = DBRepository::getDBCredentials();

        // make pg_dump process
        $oBuilder = new ProcessBuilder(array(
            '/usr/lib/postgresql/' . $aCredentials['version'] . '/bin/pg_dump',
            '--schema-only',
            '-t', $this->sSchemaName . '.' . $this->sObjectName,
            '-U', $aCredentials['user_name'],
            '-h', $aCredentials['host'],
            '-p', $aCredentials['port'],
            $aCredentials['db_name']
        ));
        $oDiff = $oBuilder->getProcess();
        $oDiff->run();

        $sOutput = $oDiff->getOutput();

        $sOutput = preg_replace("~^--.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~^SET.*~uixm", "", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);
        $sOutput = preg_replace("~\\n\\n~uixs", "\n", $sOutput);

        $sOutput = trim($sOutput);

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


