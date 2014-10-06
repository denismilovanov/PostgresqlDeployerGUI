<?php

class StoredFunction extends DatabaseObject
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT COUNT(*) AS c
                FROM pg_catalog.pg_proc AS p
                LEFT JOIN pg_catalog.pg_namespace n ON
                    n.oid = p.pronamespace
                WHERE   n.nspname = ?w AND
                        p.proname = ?w;
        ",
            $this->sSchemaName,
            $this->sObjectName
        );
    }

    public function getObjectDependencies()
    {
        return array();
    }

    public function getSignature($sFunctionBody)
    {
        preg_match("~CREATE\s+OR\s+REPLACE\s+FUNCTION.+?\((.*?)\)\s*RETURNS~uixs", $sFunctionBody, $aMatches);
        if (isset($aMatches[1])) {
            $sSignature = $aMatches[1];
            $sSignature = preg_replace("~--.*$~uixm", "", $sSignature);
            //$sSignature = preg_replace("~^\s*$~uixm", "", $sSignature);
            $sSignature = preg_replace("~,\s+~uixs", ",", $sSignature);
            $sSignature = trim($sSignature);
        } else {
            return false;
        }
        return $sSignature;
    }

    public function getReturnType($sFunctionBody)
    {
        preg_match("~CREATE\s+OR\s+REPLACE\s+FUNCTION.+?\((.*?)\)\s*RETURNS(.+?)\sAS~uixs", $sFunctionBody, $aMatches);
        if (isset($aMatches[2])) {
            $sReturnType = $aMatches[2];
            $sReturnType = trim($sReturnType);
            $sReturnType = preg_replace("~\s+~uixs", " ", $sReturnType);
        } else {
            return false;
        }
        return $sReturnType;
    }

    public function signatureChanged()
    {
        $sNewFunctionBody = $this->sObjectContent;
        $sOldFunctionBody = $this->getObjectContentInDatabase();

        $sNewSignature = $this->getSignature($sNewFunctionBody);
        $sOldSignature = $this->getSignature($sOldFunctionBody);

        return  $sNewSignature === false or
                $sOldSignature === false or
                $sNewSignature != $sOldSignature;
    }

    public function returnTypeChanged()
    {
        $sNewFunctionBody = $this->sObjectContent;
        $sOldFunctionBody = $this->getObjectContentInDatabase();

        $sNewReturnType = $this->getReturnType($sNewFunctionBody);
        $sOldReturnType = $this->getReturnType($sOldFunctionBody);

        return  $sNewReturnType === false or
                $sOldReturnType === false or
                $sNewReturnType != $sOldReturnType;
    }

    public function applyObject()
    {
        if (self::$bImitate) {
            return;
        }

        if ($this->signatureChanged() or $this->returnTypeChanged()) {
            self::$oDB->query("
                SELECT postgresql_deployer.drop_all_functions_by_name(?w, ?w);
            ",
                $this->sObjectName,
                $this->sSchemaName
            );
        }

        self::$oDB->query(self::stripTransaction($this->sObjectContent));
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash or
                ! $this->objectExists();
    }

    public function compare(StoredFunction $oAnotherFunction) {
        return  $this->sDatabaseName == $oAnotherFunction->sDatabaseName and
                $this->sSchemaName == $oAnotherFunction->sSchemaName and
                $this->sObjectIndex == $oAnotherFunction->sObjectIndex and
                $this->sObjectName == $oAnotherFunction->sObjectName;
    }

}


