<?php

// for external `diff`
use Symfony\Component\Process\ProcessBuilder;

class Diff
{
    // git stuff
    private $aInsertions = array();
    private $aDeletions = array();

    // references (table on table)
    private $aReferences = array();

    //
    public function __construct($aInsertions, $aDeletions) {
        $this->aInsertions = $aInsertions;
        $this->aDeletions = $aDeletions;
    }

    //
    public function getInsertionsCount() {
        return sizeof($this->aInsertions);
    }

    //
    public function getDeletionsCount() {
        return sizeof($this->aDeletions);
    }

    //
    public function getReferences() {
        return $this->aReferences;
    }

    // file can be forwarded automatically only when it has no significant deletions
    public function canBeForwarded() {
        return sizeof($this->aDeletions) == 0;
    }

    // file does not contain statements that can not be executed inside a transaction block
    public function canBeExecutedInsideATransactionBlock() {
        $sFullDiff = implode("\n", $this->aInsertions) . "\n" . implode("\n", $this->aDeletions);
        return ! preg_match("~(CONCURRENTLY)|(CREATE\s+TABLESPACE)|(DROP\s+TABLESPACE)~uixs", $sFullDiff, $aMatches);
    }

    //
    public function tableSignatureChanged() {
        $sFullDiff = implode(PHP_EOL, $this->aInsertions) . PHP_EOL . implode(PHP_EOL, $this->aDeletions);
        return preg_match("~(?:ADD)|(?:DROP|ALTER)\s+COLUMN~uis", $sFullDiff);
    }

    //
    public function addReference(DatabaseObject $oGivenReference) {
        foreach ($this->aReferences as $oReference) {
            if ($oGivenReference->compare($oReference)) {
                return false;
            }
        }
        $this->aReferences []= $oGivenReference;
    }

    //
    public function removeReference(DatabaseObject $oGivenReference) {
        $iCount = 0;
        foreach ($this->aReferences as $i => $oReference) {
            if ($oGivenReference->compare($oReference)) {
                unset($this->aReferences[$i]);
                $iCount ++;
                // it must be unique reference so we are able to say 'break' here
                break;
            }
        }
        return $iCount;
    }

    // code for deploying by forwarding
    public function getForwardStatements($sGlue = ' ') {
        return implode($sGlue, $this->aInsertions);
    }

    // extract table references from +insertions
    private function extractReferences() {
        // we will deploy this code
        $sCode = $this->getForwardStatements();

        // extract table references
        preg_match_all("~REFERENCES\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)~uixs", $sCode, $aMatches, PREG_SET_ORDER);

        // add them as references...
        foreach ($aMatches as $aMatch) {
            $oObject = DatabaseObject::make(
                DBRepository::getCurrentDatabase(),
                $aMatch[1],
                'tables',
                $aMatch[2],
                ''
            );
            // ...only if they do not exist
            if (! $oObject->objectExists()) {
                $this->addReference($oObject);
            }
        }

        // we can also check existing of referenced field...

        //
        return $this->aReferences;
    }

    // get difference between file content and saved object
    public static function getDiff($oObject) {
        // read file from git
        $sInRepository = DBRepository::getFileContent(
            DBRepository::getAbsoluteFileName(DBRepository::makeRelativeFileName(
                $oObject->sSchemaName,
                $oObject->sObjectIndex,
                $oObject->sObjectName
            ))
        );

        // read from database
        $sInDatabase = $oObject->getObjectContentInDatabase();

        // create 2 temporary files
        $sFileInRepository = DBRepository::makeTemporaryFile($sInRepository);
        $sFileInDatabase = DBRepository::makeTemporaryFile($sInDatabase);

        // make diff process
        $oBuilder = new ProcessBuilder(array(
            'diff',
            $sFileInDatabase,
            $sFileInRepository,
            '-U 0' // no need in context
        ));
        $oDiff = $oBuilder->getProcess();
        $oDiff->run();

        //
        unlink($sFileInRepository);
        unlink($sFileInDatabase);

        //
        $sOutput = $oDiff->getOutput();

        // process_output, get rid of not relevant lines
        $sOutput = preg_replace("~^---.*$~uixm", "", $sOutput);
        $sOutput = preg_replace("~^\+\+\+.*$~uixm", "", $sOutput);
        $sOutput = preg_replace("~^\+\s*$~uixm", "", $sOutput);
        $sOutput = preg_replace("~^\+\s*--.*$~uixm", "", $sOutput);

        $aInsertions = array();
        $aDeletions = array();

        preg_match_all("~^\+(.*)$~uixm", $sOutput, $aMatches, PREG_SET_ORDER);
        foreach ($aMatches as $aInsert) {
            if (trim($aInsert[1])) {
                $aInsertions []= $aInsert[1];
            }
        }

        preg_match_all("~^-(.*)$~uixm", $sOutput, $aMatches, PREG_SET_ORDER);
        foreach ($aMatches as $aDelete) {
            if (trim($aDelete[1])) {
                $aDeletions []= $aDelete[1];
            }
        }

        // make diff object
        $oDiff = new self($aInsertions, $aDeletions);
        $oDiff->extractReferences();

        return $oDiff;
    }

}


