<?php

class Type extends DatabaseObject
{

    public function objectExists()
    {
        return true;
    }

    public function getObjectDependencies()
    {
        if (! $this->signatureChanged()) {
            return array();
        }
        return self::$oDB->selectTable("
            SELECT  database_name,
                    schema_name AS dependency_schema_name,
                    object_index AS dependency_object_index,
                    object_name AS dependency_object_name,
                    additional_sql
                FROM postgresql_deployer.get_type_dependent_functions(?w, ?w, ?w);
        ",
            $this->sDatabaseName,
            $this->sSchemaName,
            $this->sObjectName
        );

    }

    public function getSignature($sTypeBody)
    {
        preg_match("~CREATE\s+TYPE.+?\((.*?)\);~uixs", $sTypeBody, $aMatches);
        if (isset($aMatches[1])) {
            $sSignature = $aMatches[1];
            $sSignature = preg_replace("~--.*$~uixm", "", $sSignature);
            $sSignature = preg_replace("~,\s+~uixs", ",", $sSignature);
            $sSignature = preg_replace("~\s+~uixs", " ", $sSignature);
            $sSignature = trim($sSignature);
        } else {
            return false;
        }
        return $sSignature;
    }

    public function signatureChanged()
    {
        $sNewTypeBody = $this->sObjectContent;
        $sOldTypeBody = $this->getObjectContentInDatabase();

        $sNewSignature = $this->getSignature($sNewTypeBody);
        $sOldSignature = $this->getSignature($sOldTypeBody);

        return  $sNewSignature === false or
                $sOldSignature === false or
                $sNewSignature != $sOldSignature;
    }

    public function applyObject()
    {
        if (self::$bImitate) {
            return array();
        }

        $aDroppedFunctions = array();

        if ($this->signatureChanged()) {
            $aDroppedFunctionsRaw = self::$oDB->selectTable("
                SELECT *
                    FROM postgresql_deployer.drop_type_with_dependent_functions(?w, ?w, ?w);
            ",
                $this->sDatabaseName,
                $this->sSchemaName,
                $this->sObjectName
            );

            $aDroppedFunctions = array();

            foreach ($aDroppedFunctionsRaw as $aDroppedFunctionRaw) {
                $aDroppedFunctions []= DatabaseObject::make(
                    $aDroppedFunctionRaw['database_name'],
                    $aDroppedFunctionRaw['schema_name'],
                    $aDroppedFunctionRaw['object_index'],
                    $aDroppedFunctionRaw['object_name'],
                    '1'
                );
            };

            self::$oDB->query(self::stripTransaction($this->sObjectContent));
        }

        return $aDroppedFunctions;
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash;
    }

    public function describe()
    {
        return '';
    }

}


