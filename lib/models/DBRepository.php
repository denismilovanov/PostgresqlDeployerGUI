<?php

use Gitonomy\Git\Repository;
use Gitonomy\Git\Reference\Branch;
use LibPostgres\LibPostgresDriver;

// for external `diff`
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class DBRepository
{
    private static $oGit = null;
    private static $oDB = null;
    private static $sDirectory = null;
    private static $sDatabase = null;
    private static $sSchemasPath = 'schemas/';
    public static $sLastStatement = '';

    public static $aDatabases = array();
    public static $aBranches = array();

    /**
     * Reads settings from JSON configuration file.
     *
     * @param none
     *
     * @return none
     */

    public static function readSettings()
    {
        $sFileName = './../lib/config/databases.json';

        if (! file_exists($sFileName) or ! is_readable($sFileName)) {
            self::$aDatabases = array();
            return;
        }

        $aSettings = json_decode(file_get_contents($sFileName), 'associative');

        // allowed databases
        self::$aDatabases = isset($aSettings['databases']) ? $aSettings['databases'] : array();
    }

    public static function sameDatabasesExist()
    {
        $aDatabasesNames = array();
        foreach (self::$aDatabases as $aDatabase) {
            if (isset($aDatabase['credentials']['db_name'])) {
                $aDatabasesNames []= $aDatabase['credentials']['db_name'];
            }
        }
        return sizeof(array_unique($aDatabasesNames)) != sizeof($aDatabasesNames);
    }

    /**
     * Returns allowed databases.
     *
     * @param none
     *
     * @return array databases
     */

    public static function getDatabases()
    {
        return self::$aDatabases;
    }

    /**
     * Returns existing branches.
     *
     * @param none
     *
     * @return array branches
     */

    public static function getBranches()
    {
        return self::$aBranches;
    }

    /**
     * Returns current branch.
     *
     * @param none
     *
     * @return string current branch
     */

    public static function getCurrentBranch()
    {
        $aBranches = trim(self::$oGit->run('branch'));
        $aBranches = explode("\n", $aBranches);
        foreach ($aBranches as $sBranch) {
            $sBranch = trim($sBranch);
            if ($sBranch[0] == '*') {
                $sBranch = str_replace("* ", "", $sBranch);
                $sBranch = str_replace("(detached from ", "", $sBranch);
                $sBranch = str_replace(")", "", $sBranch);
                $sBranch = trim($sBranch);
                return $sBranch;
            }
        }
    }

    /**
     * Takes index of single database. Returns allowed databases.
     *
     * @param string database index
     *
     * @return array|false one database
     */

    public static function useDatabase($sDatabaseIndex)
    {
        // all databases from config
        $aDatabases = self::getDatabases();

        // we do not know it
        if (! isset($aDatabases[$sDatabaseIndex])) {
            throw new Exception("There is no database '$sDatabaseIndex'.");
        }

        // no git root
        if (! isset($aDatabases[$sDatabaseIndex]['git_root'])) {
            throw new Exception("There is no git_root '$sDatabaseIndex'.");
        }

        // no access credentials
        if (! isset($aDatabases[$sDatabaseIndex]['credentials'])) {
            throw new Exception("There is no credentials for '$sDatabaseIndex'.");
        }

        // make connection
        self::$oDB = new LibPostgresDriver($aDatabases[$sDatabaseIndex]['credentials']);
        // check connection
        self::$oDB->selectField("SELECT 1");

        // share connections
        User::$oDB = self::$oDB;
        DatabaseObject::$oDB = self::$oDB;

        // make git
        self::$oGit = new Repository($aDatabases[$sDatabaseIndex]['git_root']);

        // branches
        self::$aBranches = array();
        foreach (self::$oGit->getReferences()->getLocalBranches() as $oBranch) {
            self::$aBranches []= array(
                'name' => trim($oBranch->getName()),
                'hash' => $oBranch->getCommitHash(),
            );
        }

        // save params
        self::$sDatabase = $sDatabaseIndex;
        self::$sDirectory = $aDatabases[$sDatabaseIndex]['git_root'];

        // return
        return $aDatabases[$sDatabaseIndex];
    }

    /**
     * Gets commits of git branch
     *
     * @param none
     *
     * @return array Commits
     */

    public static function getCommits()
    {
        $aCommitsRaw = self::$oGit->getLog(null);
        $aCommits = array(
            'commits' => array(),
            'current_commit_hash' => '',
        );

        $bIsHeadDetached = self::$oGit->isHeadDetached();

        foreach ($aCommitsRaw as $aCommit) {
            if ($bIsHeadDetached) {
                $bActive = $aCommit->getHash() == self::$oGit->getHead()->getHash();
            } else {
                $bActive = $aCommit->getHash() == self::$oGit->getHeadCommit()->getHash();
            }

            if ($bActive) {
                $aCommits['current_commit_hash'] = $aCommit->getHash();
            }

            $aBranchesRaw = $aCommit->resolveReferences();

            $aBranches = array();
            foreach ($aBranchesRaw as $aBranch) {
                if ($aBranch instanceof Branch) {
                    if ($aBranch->isLocal()) {
                        $aBranches [] = array(
                            'branch_name' => $aBranch->getName(),
                        );
                    }
                }
            }

            $aCommits['commits'] []= array(
                'commit_hash' => $aCommit->getHash(),
                'commit_message' => $aCommit->getMessage(),
                'commit_active' => $bActive ? "active" : "passive",
                'resolved_branches' => $aBranches,
            );
        }
        return $aCommits;
    }

    /**
     * Gets allowed database object types
     *
     * @param none
     *
     * @return array types
     */

    private static function getObjectsIndexes()
    {
        return array('tables', 'types', 'seeds', 'functions');
    }

    /**
     * Checkouts to specific commit by its hash
     *
     * @param string commit hash
     *
     * @return array types
     */

    public static function checkout($sHash)
    {
        self::$oGit->run("checkout", array($sHash));
        return self::reload();
    }

    /**
     * Get schemas in git repository - schemas are subdirectories in database directory
     *
     * @param none
     *
     * @return array schemas
     */

    private static function getSchemas()
    {
        $aSchemasRaw = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath, false);
        $aSchemas = array();

        foreach ($aSchemasRaw as $sFile) {
            $aSchemas []= self::getBaseName($sFile['file']);
        }

        sort($aSchemas);

        return $aSchemas;
    }

    /**
     * Reloads current state of git in compare with current database state
     *
     * @param none
     *
     * @return array schemas
     */

    private static function reload()
    {
        //
        $aSchemas = self::getSchemas();

        // current state of migration table
        DatabaseObject::readMigrations();

        $aResult = array(
            'schemas' => array(),
        );

        // for each schema
        foreach ($aSchemas as $sSchema) {

            // for each object type - index
            foreach (self::getObjectsIndexes() as $sObjectIndex) {

                $aSchema = array();

                $aSchema['database_name'] = self::$sDatabase;
                $aSchema['schema_name'] = $sSchema;
                $aSchema['object_index'] = $sObjectIndex;

                // all objects of given type in given schema
                $aFiles = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath . $sSchema . "/" . $sObjectIndex);

                sort($aFiles);

                // let's walk through files
                foreach ($aFiles as $aFile) {

                    $sObjectNameName = self::getBaseNameWithoutExtension($aFile['file']);
                    $aDependencies = null;

                    // make object
                    $oDatabaseObject = DatabaseObject::make(
                        self::$sDatabase,
                        $sSchema,
                        $sObjectIndex,
                        $sObjectNameName,
                        self::getFileContent($aFile['file'])
                    );

                    // has object been changed (git contains one version, but db contains another)
                    if ($oDatabaseObject->hasChanged($aFile['hash'])) {

                        // we should show dependencies
                        $aDependencies = $oDatabaseObject->getObjectDependencies();

                        $bIsNew = ! $oDatabaseObject->objectExists();

                        if (! $bIsNew) {
                            $bSignatureChanged = $oDatabaseObject->signatureChanged();
                            $bReturnTypeChanged = ($oDatabaseObject instanceof StoredFunction) && $oDatabaseObject->returnTypeChanged();
                        } else {
                            // it has no sense showing it
                            $bSignatureChanged = false;
                            $bReturnTypeChanged = false;
                        }

                        $aSchema['objects'] []= array(
                            'object_name' => $sObjectNameName,
                            'dependencies' => $aDependencies,
                            'dependencies_exist' => $aDependencies ? true : null,
                            'signature_changed' => $bSignatureChanged,
                            'return_type_changed' => $bReturnTypeChanged,
                            'manual_deployment_required' => $oDatabaseObject instanceof Table ? true : null,
                            'new_object' => $bIsNew,
                        );
                    }

                }

                if (! empty($aSchema['objects'])) {
                    $aResult['schemas'] []= $aSchema;
                }

            }

        }

        return $aResult;
    }

    /**
     * Returns list of files in directory
     *
     * @param string directory
     *
     * @return array files
     */

    private static function getListOfFiles($sDirectory, $bRecursive = true, $bAndDirs = false)
    {
        if (substr($sDirectory, -1) != "/") {
            $sDirectory .= "/";
        }

        if (! is_dir($sDirectory) or ! file_exists($sDirectory) or ! is_readable($sDirectory)) {
            return array();
        }

        $aResult = array();
        $rHandle = opendir($sDirectory);

        if (! $rHandle) {
            return array();
        }

        while (false !== ($sFile = readdir($rHandle))) {
            if ($sFile != "." and $sFile != ".." and $sFile != ".git") {
                $sFile = $sDirectory . $sFile;

                if(is_dir($sFile)){
                    if ($bRecursive) {
                        $aResult = array_merge($aResult, ListOfFiles::getListOfFiles($sFile, $bRecursive));
                    }
                    $aResult []= array(
                        'file' => $sFile,
                        'hash' => '',
                    );
                } else {
                    $aResult []= array(
                        'file' => $sFile,
                        'hash' => self::getFileHash($sFile),
                    );
                }
            }
        }

        closedir($rHandle);
        return $aResult;
    }

    /**
     * Makes temporary file with given content
     *
     * @param string content
     *
     * @return string filename
     */

    private static function makeTemporaryFile($sContent)
    {
        $sFileName = tempnam(sys_get_temp_dir(), 'pgdeployer');
        file_put_contents($sFileName, $sContent);
        return $sFileName;
    }

    /**
     * Returns diff between object in git and object saved in database
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     * @param integer context
     *
     * @return string diff as HTML
     */

    public static function getDiffAsHTML($sSchemaName, $sObjectIndex, $sObjectName, $iContext = 5)
    {
        // read file from git
        $sInRepository = self::getFileContent(
            self::getAbsoluteFileName(self::makeRelativeFileName($sSchemaName, $sObjectIndex, $sObjectName))
        );

        // make object via git
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            '', ''
        );

        // read from database
        $sInDatabase = $oObject->getObjectContentInDatabase();

        // create 2 temporary files
        $sFileInRepository = self::makeTemporaryFile($sInRepository);
        $sFileInDatabase = self::makeTemporaryFile($sInDatabase);

        // make diff process
        $oBuilder = new ProcessBuilder(array(
            'diff',
            $sFileInDatabase,
            $sFileInRepository,
            '-U ' . $iContext
        ));
        $oDiff = $oBuilder->getProcess();
        $oDiff->run();

        $sOutput = $oDiff->getOutput();

        // process_output
        $sOutput = preg_replace("~^---.*$~uixm", "", $sOutput);
        $sOutput = preg_replace("~^\+\+\+.*$~uixm", "", $sOutput);

        $sOutput = preg_replace("~^(-.*)$~uixm", "<del>$1</del>", $sOutput);
        $sOutput = preg_replace("~^(\+.*)$~uixm", "<ins>$1</ins>", $sOutput);
        $sOutput = preg_replace("~^(@@.+@@)$~uixm", "<tt>$1</tt>", $sOutput);
        $sOutput = trim($sOutput);

        unlink($sFileInRepository);
        unlink($sFileInDatabase);

        return array(
            'in_database' => $sOutput,
        );
    }

    /**
     * Returns hash of file
     *
     * @param string filenae
     *
     * @return string hash
     */

    private static function getFileHash($sFilename)
    {
        return DatabaseObject::getHash(self::getFileContent($sFilename));
    }

    /**
     * Returns hash of file
     *
     * @param string absolute filename
     *
     * @return string hash
     */

    private static function getFileContent($sFilename)
    {
        return file_get_contents($sFilename);
    }

    /**
     * Returns basename of file
     *
     * @param string absolute or relative filename
     *
     * @return string basenae
     */

    private static function getBaseName($sFilename)
    {
        $aPathInfo = pathinfo($sFilename);
        return $aPathInfo['basename'];
    }

    /**
     * Returns basename of file without extension
     *
     * @param string absolute or relative filename
     *
     * @return string basename
     */

    private static function getBaseNameWithoutExtension($sFilename)
    {
        $aPathInfo = pathinfo($sFilename);
        return $aPathInfo['filename'];
    }

    /**
     * Makes absolute filename based on relative filename inside git repository and database
     *
     * @param string relative filename
     *
     * @return string absolute filename
     */

    private static function getAbsoluteFileName($sRelativeFileName)
    {
        return self::$sDirectory . self::$sSchemasPath . $sRelativeFileName;
    }

    /**
     * Makes relative filename based on object data
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return string absolute filename
     */

    private function makeRelativeFileName($sSchemaName, $sObjectIndex, $sObjectName) {
        return $sSchemaName . "/" . $sObjectIndex . "/" . $sObjectName . ".sql";
    }

    /**
     * Deploys (applies) given objects to database
     *
     * @param array objects
     * @param boolean imitate? - fill migration table without performing deployment
     *
     * @return void
     */

    public static function apply($aObjects, $bImitate)
    {
        if (! $aObjects) {
            return;
        }

        // to fill column in migration_log
        DatabaseObject::$sCommitHash = self::$oGit->getHeadCommit()->getHash();

        // to remember we need imitation
        DatabaseObject::$bImitate = $bImitate;

        $aTables = array();
        $aTypes = array();
        $aFunctions = array();
        $aSeeds = array();

        // for each object
        foreach ($aObjects as $sRelativeFileName) {
            // exploding by / to get object data
            $aParts = explode("/", $sRelativeFileName);

            $sSchemaName = $aParts[0];
            $sObjectIndex = $aParts[1];
            $sBaseName = $aParts[2];

            $sRelativeFileName = self::makeRelativeFileName($sSchemaName, $sObjectIndex, $sBaseName);

            // make object
            $aObject = DatabaseObject::make(
                self::$sDatabase,
                $sSchemaName,
                $sObjectIndex,
                self::getBaseNameWithoutExtension($sBaseName),
                self::getFileContent(self::getAbsoluteFileName($sRelativeFileName))
            );

            // sorting into 4 types
            if ($sObjectIndex == 'tables') {
                $aTables []= $aObject;
            } else if ($sObjectIndex == 'functions') {
                $aFunctions []= $aObject;
            } else if ($sObjectIndex == 'types') {
                $aTypes []= $aObject;
            } else if ($sObjectIndex == 'seeds') {
                $aSeeds []= $aObject;
            }
        }

        // the heart of deployer - single transaction
        try {
            self::$oDB->startTransaction();

            $aDroppedFunctions = array();

            // let's start tith types
            foreach ($aTypes as $aType) {
                $aDroppedFunctions = $aType->applyObject();
                $aType->upsertMigration();
            }

            // applying type may cause some functions to be dropped
            foreach ($aDroppedFunctions as $aDroppedFunction) {

                // is dropped function already to be deployed?
                $bFound = false;
                foreach ($aFunctions as $aFunction) {
                    if ($aFunction->compare($aDroppedFunction)) {
                        $bFound = true;
                        break;
                    }
                }

                if (! $bFound) {
                    // no, we have to add it
                    $sDroppedFileName = self::makeRelativeFileName(
                                            $aDroppedFunction->sSchemaName,
                                            $aDroppedFunction->sObjectIndex,
                                            $aDroppedFunction->sObjectName);
                    //
                    $aFunctions []= DatabaseObject::make(
                        self::$sDatabase,
                        $aDroppedFunction->sSchemaName,
                        $aDroppedFunction->sObjectIndex,
                        $aDroppedFunction->sObjectName,
                        self::getFileContent(self::getAbsoluteFileName($sDroppedFileName))
                    );
                }
            }

            // deploying functions
            foreach ($aFunctions as $aFunction) {
                $aFunction->applyObject();
                $aFunction->upsertMigration();
            }

            // "deploying" tables
            foreach ($aTables as $aTable) {
                $aTable->applyObject();
                $aTable->upsertMigration();
            }

            // deploying seeds
            foreach ($aSeeds as $aSeed) {
                $aSeed->applyObject();
                $aSeed->upsertMigration();
            }

            // say commit
            self::$oDB->commit();

        } catch (Exception $oException) {
            // throw further
            throw $oException;
        }

    }

}


