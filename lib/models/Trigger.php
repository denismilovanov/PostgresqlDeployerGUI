<?php

class Trigger extends DatabaseObject
{
    public function getTableAndTrigger() {
        $aParts = explode(".", $this->sObjectName);
        return array(
            'table_name' => isset($aParts[0]) ? trim($aParts[0]) : '',
            'trigger_name' => isset($aParts[1]) ? trim($aParts[1]) : '',
        );
    }

    public function objectExists()
    {
        $aParts = $this->getTableAndTrigger();

        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM pg_catalog.pg_trigger AS t
                WHERE   t.tgrelid = (
                            SELECT c.oid
                                FROM pg_catalog.pg_class c
                                INNER JOIN pg_catalog.pg_namespace n ON
                                    n.oid = c.relnamespace
                                WHERE   c.relname = ?w AND
                                        n.nspname = ?w
                        ) AND
                        t.tgname = ?w;
        ",
            $aParts['table_name'],
            $this->sSchemaName,
            $aParts['trigger_name']
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

        $this->drop();

        self::$oDB->query(self::stripTransaction($this->sObjectContent));

    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash;
    }

    public function isDescribable ()
    {
        return false;
    }

    public function isDefinable ()
    {
        return false;
    }

    public function isDroppable ()
    {
        return true;
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
        $aParts = $this->getTableAndTrigger();

        if ($aParts['trigger_name'] and $aParts['table_name'] and $this->objectExists()) {
            self::$oDB->selectField("
                DROP TRIGGER ? ON ?.? RESTRICT;
                SELECT 1;
            ",
                $aParts['trigger_name'],
                $this->sSchemaName,
                $aParts['table_name']
            );
        }
    }
}


