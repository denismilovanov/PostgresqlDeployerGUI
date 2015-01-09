<?php

use Gitonomy\Git\Repository;
use Gitonomy\Git\Reference\Branch;
use LibPostgres\LibPostgresDriver;

// for external `diff`
use Symfony\Component\Process\ProcessBuilder;

class DBRepository
{
    private static $oGit = null;
    private static $aDBCredentials = null;
    private static $oDB = null;
    private static $sDirectory = null;
    private static $sDatabase = null;
    private static $sSchemasPath = 'schemas/';

    private static $oLastAppliedObject = null;

    // settings of features
    private static $aSettings = array();

    // databases user can access and work with
    public static $aDatabases = array();

    // branches of chosen repository
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
        $sFileName = './../lib/config/settings.json';

        if (! file_exists($sFileName) or ! is_readable($sFileName)) {
            self::$aSettings = array();
            return;
        }

        $aSettings = json_decode(file_get_contents($sFileName), 'associative');

        // settings
        self::$aSettings = isset($aSettings['settings']) ? $aSettings['settings'] : array();
    }

    /**
     * Gets setting value
     *
     * @param string $sIndex composite, dot-imploded array index, e.g. 'not_in_git.active'
     * @param mixed $mDefaultValue default value for case $sIndex is absent in settings array
     *
     * @return mixed setting value
     */

    public static function getSettingValue($sIndex, $mDefaultValue = false)
    {
        $aIndexes = explode(".", $sIndex);
        $sExpression = '';
        foreach ($aIndexes as $sIndex) {
            $sExpression .= "['" . $sIndex . "']";
        }
        if (eval('return isset(self::$aSettings' . $sExpression . ');')) {
            $mValue = eval('return self::$aSettings' . $sExpression . ';');
            return $mValue;
        } else {
            return $mDefaultValue;
        }
    }

    /**
     * Reads databases from JSON configuration file.
     *
     * @param none
     *
     * @return none
     */

    public static function readDatabases()
    {
        $sFileName = './../lib/config/databases.json';

        if (! file_exists($sFileName) or ! is_readable($sFileName)) {
            self::$aDatabases = array();
            return;
        }

        $aDatabases = json_decode(file_get_contents($sFileName), 'associative');

        // allowed databases
        self::$aDatabases = isset($aDatabases['databases']) ? $aDatabases['databases'] : array();
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
     * Returns current database.
     *
     * @param none
     *
     * @return string database
     */

    public static function getCurrentDatabase()
    {
        return self::$sDatabase;
    }

    /**
     * Returns DB credentials.
     *
     * @param none
     *
     * @return array credentials
     */

    public static function getDBCredentials()
    {
        return self::$aDBCredentials;
    }

    /**
     * Returns last applied object.
     *
     * @param none
     *
     * @return object
     */

    public static function getLastAppliedObject()
    {
        return self::$oLastAppliedObject;
    }

    /**
     * Sets last applied object.
     *
     * @param object last applied object
     *
     * @return none
     */

    public static function setLastAppliedObject($oLastAppliedObject)
    {
        self::$oLastAppliedObject = $oLastAppliedObject;
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

        // schemas_path can be overriden
        if (isset($aDatabases[$sDatabaseIndex]['schemas_path'])) {
            self::$sSchemasPath = $aDatabases[$sDatabaseIndex]['schemas_path'];
        }

        // make connection
        self::$aDBCredentials = $aDatabases[$sDatabaseIndex]['credentials'];
        self::$oDB = new LibPostgresDriver(self::$aDBCredentials);
        // check connection
        $sVersion = self::$oDB->selectField("SHOW server_version_num;");

        // build version
        self::$aDBCredentials['version'] = floor($sVersion /  10000) . "." . floor($sVersion / 100) % 10;
        // to show in header
        $aDatabases[$sDatabaseIndex]['version'] = self::$aDBCredentials['version'];

        // share connections
        User::$oDB = self::$oDB;
        Database::$oDB = self::$oDB;
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
                'commit_author' => $aCommit->getAuthorName(),
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
     * Get schemas in git repository (schemas are subdirectories in database directory) + database schemas
     *
     * @param none
     *
     * @return array schemas
     */

    private static function getSchemas()
    {
        // schemas from git
        $aSchemasRaw = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath, false);
        $aSchemas = array();

        foreach ($aSchemasRaw as $sFile) {
            $sSchemaName = self::getBaseName($sFile['file']);
            $aSchemas[$sSchemaName] = $sSchemaName;
        }

        // merge with schemas from database
        $aSchemas = array_merge($aSchemas, Database::getSchemas());

        // some order
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

        //
        $bShowObjectsNotInGit = self::getSettingValue('not_in_git.active');

        // for each schema
        foreach ($aSchemas as $sSchema) {

            // for each object type - index
            foreach (self::getObjectsIndexes() as $sObjectIndex) {

                // container for objects in schema
                $aSchema = array();

                $aSchema['database_name'] = self::$sDatabase;
                $aSchema['schema_name'] = $sSchema;
                $aSchema['object_index'] = $sObjectIndex;

                // all objects of given type in given schema
                $aFiles = self::getListOfFiles(self::$sDirectory . self::$sSchemasPath . $sSchema . "/" . $sObjectIndex);
                // files have hash != ''

                if ($bShowObjectsNotInGit) {
                    // ALL objects in database
                    $aObjects = Database::getObjectsAsVirtualFiles($sSchema, $sObjectIndex);
                    // hash == ''

                    // objects not under git will still have hash = ''
                    $aFiles = array_merge($aObjects, $aFiles);
                }

                //
                sort($aFiles);

                // let's walk through files
                foreach ($aFiles as $aFile) {

                    $sObjectNameName = self::getBaseNameWithoutExtension($aFile['file']);
                    $aDependencies = null;

                    // is object under git?
                    $bInGit = $aFile['hash'] != '';
                    $bNotInGit = ! $bInGit;

                    // make object
                    $oDatabaseObject = DatabaseObject::make(
                        self::$sDatabase,
                        $sSchema,
                        $sObjectIndex,
                        $sObjectNameName,
                        $bInGit ? self::getFileContent($aFile['file']) : ''
                    );

                    // has object been changed (git contains one version, but db contains another)
                    if ($oDatabaseObject->hasChanged($aFile['hash'])) {

                        // we should show dependencies
                        $aDependencies = $oDatabaseObject->getObjectDependencies();

                        // is object new? (in git and not in database)
                        $bIsNew = ! $oDatabaseObject->objectExists();

                        //
                        if (! $bIsNew and $bInGit) {
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
                            'manual_deployment_required' => (($oDatabaseObject instanceof Table) and $bInGit) ? true : null,
                            'new_object' => $bIsNew,
                            'not_in_git' => $bNotInGit,
                            'define' => $bNotInGit and ($oDatabaseObject instanceof Table or $oDatabaseObject instanceof Type),
                            'drop' => $bNotInGit and ($oDatabaseObject instanceof Table),
                            'describe' => $bInGit and ! $bIsNew and ($oDatabaseObject instanceof Table or $oDatabaseObject instanceof Type),
                        );
                    }

                }

                if (! empty($aSchema['objects'])) {
                    $aSchema['objects_count'] = count($aSchema['objects']);
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
                    $aResult [self::getBaseNameWithoutExtension($sFile)]= array(
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

        $sOutput = preg_replace("~^-(.*)$~uixm", "<del>$1</del>", $sOutput);
        $sOutput = preg_replace("~^\+(.*)$~uixm", "<ins>$1</ins>", $sOutput);
        $sOutput = preg_replace("~^(@@.+@@)$~uixm", "<tt>$1</tt>", $sOutput);
        $sOutput = preg_replace("~^\s~uixm", "", $sOutput);
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

            //
            if (self::getSettingValue('plpgsql_check.active')) {
                // let's check stored functions
                Database::checkAllStoredFunctionsByPlpgsqlCheck();
                // says rollback and throws exception if check fails
            }

            // say commit
            self::$oDB->commit();

        } catch (Exception $oException) {
            // throw further
            throw $oException;
        }

    }

    /**
     * Makes object definition
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return Object SQL definition (pg_dump output for tables and types)
     */

    public static function define($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->define();
    }

    /**
     * Makes object description
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return Object description (psql output for tables and types)
     */

    public static function describe($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->describe();
    }

    /**
     * Drops object
     *
     * @param string schema name
     * @param string object index
     * @param string object name
     *
     * @return boolean true if table was dropped
     */

    public static function drop($sSchemaName, $sObjectIndex, $sObjectName)
    {
        // make object
        $oObject = DatabaseObject::make(
            self::$sDatabase,
            $sSchemaName,
            $sObjectIndex,
            $sObjectName,
            ''
        );

        return $oObject->drop();
    }

    public static function callExternalTool($sTool, $aCmd)
    {
        $aAdditionalCmd = array();

        if ($sTool == 'psql' or $sTool == 'pg_dump') {
            // to get server version and connection params
            $aCredentials = DBRepository::getDBCredentials();

            // path from settings
            $sPath = self::getSettingValue('paths.pg_bin', '/usr/lib/postgresql/%v/bin/');
            // replace %v for version
            $sPath = str_replace("%v", $aCredentials['version'], $sPath);
            // command to be executed = path + bin
            $sCmd = $sPath . $sTool;

            // credentials are needed
            $aAdditionalCmd = array
            (
                '-U', $aCredentials['user_name'],
                '-h', $aCredentials['host'],
                '-p', $aCredentials['port'],
                $aCredentials['db_name']
            );
        }

        // merge command and its arguments
        $aCmd = array_merge(
            array($sCmd),
            $aCmd,
            $aAdditionalCmd
        );

        // make pg_dump process
        $oBuilder = new ProcessBuilder($aCmd);
        $oProcess = $oBuilder->getProcess();
        $oProcess->run();
        return $oProcess->getOutput();
    }

    /**
     * Gets initial messages
     *
     * @return array messages
     */

    public static function getInitialMessages()
    {
        return Database::getPlpgsqlCheckStatus();
    }

}


