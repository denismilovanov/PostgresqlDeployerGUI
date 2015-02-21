<?php

class Query extends DatabaseObject implements IForwardable
{
    protected static $iTypeId;

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM postgresql_deployer.migrations
                WHERE   schema_name = ?w AND
                        object_name = ?w AND
                        type_id = ?d;
        ",
            $this->sSchemaName,
            $this->sObjectName,
            static::$iTypeId // late static bindings
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
        $sOutput = self::$oDB->selectField("
            SELECT content
                FROM postgresql_deployer.migrations
                WHERE   schema_name = ?w AND
                        object_name = ?w AND
                        type_id = ?d;
        ",
            $this->sSchemaName,
            $this->sObjectName,
            static::$iTypeId
        );

        return array(
            'description' => $sOutput,
            'error' => '',
        );
    }

    public function drop()
    {
        if ($this->objectExists()) {
            self::$oDB->t()->query("
                DELETE FROM postgresql_deployer.migrations
                    WHERE   schema_name = ?w AND
                            object_name = ?w AND
                            type_id = ?d;
            ",
                // this variables were checked in objectExists so we can drop nothing but pointed query
                $this->sSchemaName,
                $this->sObjectName,
                static::$iTypeId
            );
        } else {
            throw new Exception("There is no query.");
        }

        return true;
    }

}

class QueryBefore extends Query {
    protected static $iTypeId = 6;
}

class QueryAfter extends Query {
    protected static $iTypeId = 7;
}



