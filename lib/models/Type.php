<?php

class Type extends DatabaseObject
{

    public function objectExists()
    {
        return (boolean)self::$oDB->selectField("
            SELECT 1
                FROM information_schema.user_defined_types
                WHERE   user_defined_type_schema = ?w AND
                        user_defined_type_name = ?w
        ",
            $this->sSchemaName,
            $this->sObjectName
        );
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

        DBRepository::setLastAppliedObject($this);

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
        $aColumns = self::$oDB->selectColumn("
            SELECT  E'\t' || attribute_name || ' ' ||
                    CASE data_type
                        WHEN 'character varying' THEN data_type ||
                            CASE WHEN character_maximum_length IS NOT NULL THEN '(' || character_maximum_length || ')'
                                 ELSE ''
                            END
                        WHEN 'numeric' THEN data_type ||
                            CASE WHEN numeric_precision IS NOT NULL THEN '(' || numeric_precision || ',' || numeric_scale ||  ')'
                                 ELSE ''
                            END
                        WHEN 'ARRAY' THEN attribute_udt_schema || '.' || substring(attribute_udt_name from 2) || '[]'
                        WHEN 'USER-DEFINED' THEN attribute_udt_schema || '.' || attribute_udt_name
                        ELSE data_type
                    END
                FROM information_schema.attributes
                WHERE   udt_schema = ?w AND
                        udt_name = ?w
                ORDER BY ordinal_position;
        ",
            $this->sSchemaName,
            $this->sObjectName
        );

        return array(
            'definition' => "CREATE TYPE " . $this->sSchemaName . "." . $this->sObjectName . " AS (\n" .
                                implode(",\n", $aColumns) . "\n);",
            'error' => '',
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
                DROP TYPE ?.? CASCADE;
            ",
                $this->sSchemaName,
                $this->sObjectName
            );
        } else {
            throw new Exception("There is no type.");
        }

        return true;
    }

}


