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

    public function getGrants($sFunctionBody)
    {
        preg_match("~GRANT\s+EXECUTE\s+ON\s+FUNCTION.+?\(.*?\)\s*TO\s*(.*?);~uixs", $sFunctionBody, $aMatches);
        if (isset($aMatches[1])) {
            $sGrants = trim($aMatches[1]);
        } else {
            return false;
        }
        return $sGrants;
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

    public function grantsChanged()
    {
        $sNewFunctionBody = $this->sObjectContent;
        $sOldFunctionBody = $this->getObjectContentInDatabase();

        $sNewGrants = $this->getGrants($sNewFunctionBody);
        $sOldGrants = $this->getGrants($sOldFunctionBody);

        return $sNewGrants != $sOldGrants;
    }

    public function applyObject()
    {
        if (self::$bImitate) {
            return;
        }

        if ($this->signatureChanged() or $this->returnTypeChanged() or $this->grantsChanged()) {
            self::$oDB->query("
                SELECT postgresql_deployer.drop_all_functions_by_name(?w, ?w);
            ",
                $this->sObjectName,
                $this->sSchemaName
            );
        }

        DBRepository::setLastAppliedObject($this);

        self::$oDB->query(self::stripTransaction($this->sObjectContent));
    }

    public function hasChanged($sCurrentHash)
    {
        return  ! isset(self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName]) or
                self::$aMigrations[$this->sSchemaName][$this->sObjectIndex][$this->sObjectName] != $sCurrentHash or
                ! $this->objectExists();
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
        $aDescription = $this->describe();
        return array(
            'definition' => $aDescription['description'],
            'error' => $aDescription['error'],
        );
    }

    public function describe()
    {
        $aFunctions = self::$oDB->selectColumn("
            SELECT p.oid::regprocedure
                FROM pg_catalog.pg_proc AS p
                LEFT JOIN pg_catalog.pg_namespace n ON
                    n.oid = p.pronamespace
                WHERE   n.nspname = ?w AND
                        p.proname = ?w;
        ",
            $this->sSchemaName,
            $this->sObjectName
        );

        $sOutput = $sError = '';

        foreach ($aFunctions as $sFunction) {
            $sOutput .= DBRepository::callExternalTool(
                'psql',
                array('-c\sf ' . $sFunction),
                $sError
            );

            $sOutput .= "\n\n\n";

            if ($sError) {
                $sOutput = '';
                break;
            }
        }

        return array(
            'description' => trim($sOutput),
            'error' => $sError,
        );
    }

    public function drop()
    {
        if ($this->objectExists()) {
            // single transaction
            // compare with applyObject (where transaction is already open)
            self::$oDB->t()->query("
                SELECT postgresql_deployer.drop_all_functions_by_name(?w, ?w);
            ",
                $this->sObjectName,
                $this->sSchemaName
            );
        } else {
            throw new Exception("There is no function.");
        }

        return true;
    }

}


