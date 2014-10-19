<?php

// for external `pg_dump`
use Symfony\Component\Process\ProcessBuilder;

class Table extends DatabaseObject
{

    public function objectExists()
    {
        return true;
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

    public function describe()
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

}


