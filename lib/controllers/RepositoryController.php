<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class RepositoryController {

    // authorization cookie
    public static function setAuthorizationCookie($aResponse, $sCookieValue, $sDatabaseName) {
        $sCookie = new Symfony\Component\HttpFoundation\Cookie(
            "uid[$sDatabaseName]",
            $sCookieValue,
            time() + 24 * 3600, '/', null, 0, 0
        ); // last 0 == http only
        $aResponse->headers->setCookie($sCookie);
        return $aResponse;
    }

    public static function getAuthorizationCookie($sDatabaseName)
    {
        return isset($_COOKIE['uid'][$sDatabaseName]) ? $_COOKIE['uid'][$sDatabaseName] : '';
    }

    // redirect to first database in list
    public function redirectToDatabase(Request $request, Application $app) {
        $aDatabases = DBRepository::getDatabases();
        if (! $aDatabases) {
            return $app->redirect('/error/no_databases/');
        }
        $aDatabase = array_shift(array_values($aDatabases));
        return $app->redirect('/' . $aDatabase['index'] . '/');
    }

    // before all controllers
    public function useDatabase(Request $request, Application $app) {
        // given database
        $sDatabaseName = $app['request']->get('database_name');

        // can we use it?
        try {
            $aCurrentDatabase = DBRepository::useDatabase($sDatabaseName);
        } catch (Exception $oException) {
            // e.g. credentials are wrong
            return $app->redirect("/error/error/?e=" . $oException->getMessage());
        }

        // do we have 2 similar databases in config?
        if (DBRepository::sameDatabasesExist()) {
            return $app->redirect("/error/same_databases/");
        }

        // try to authorize
        try {
            $aCurrentUser = User::authorize(
                $sDatabaseName,
                self::getAuthorizationCookie($sDatabaseName)
            );
        } catch (Exception $oException) {
            return $app->redirect("/error/error/?e=" . $oException->getMessage());
        }

        //
        if ($app['request']->get('_controller') != 'RepositoryController::login' and ! $aCurrentUser['id']) {
            // redirects to login
            return $app->redirect("/" . $sDatabaseName . "/login/");
        }

        // authorized successfully
        $app['twig']->addGlobal('aCurrentUser', $aCurrentUser);
        $app['twig']->addGlobal('aCurrentDatabase', $aCurrentDatabase);
        $app['twig']->addGlobal('aDatabases', DBRepository::getDatabases());
        $app['twig']->addGlobal('aBranches', DBRepository::getBranches());
        $app['twig']->addGlobal('sCurrentBranch', DBRepository::getCurrentBranch());

        // this user will be deploying schema
        DatabaseObject::$iCurrentUserId = $aCurrentUser['id'];
    }

    //
    // actions are:
    //

    // action login (html)
    public function login(Request $request, Application $app) {
        $sDatabaseName = $app['request']->get('database_name');
        $sEmail = (string)$app['request']->get('email');
        $sPassword = (string)$app['request']->get('password');

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if ($sCookie = User::login($sEmail, $sPassword)) {
                $aResponse = $app->redirect("/$sDatabaseName/");
                return $this->setAuthorizationCookie($aResponse, $sCookie, $sDatabaseName);
            }
        }

        return $app['twig']->render('/login.haml', array(
            'sEmail' => $sEmail,
            'sDatabaseName' => $sDatabaseName,
        ));
    }

    // action logout (html)
    public function logout(Request $request, Application $app) {
        $sDatabaseName = $app['request']->get('database_name');
        $aResponse = $app->redirect("/");
        // remove cookie and redirects
        return $this->setAuthorizationCookie($aResponse, '', $sDatabaseName);
    }

    // action index
    public function index(Request $request, Application $app) {
        return $app['twig']->render('/index.haml', array(

        ));
    }

    // action error
    public function error(Request $request, Application $app) {
        return $app['twig']->render('/error.haml', array(
            'aCurrentUser' => array('email' => ''),
            'sError' => $app['request']->get('error_type'),
            'aCurrentDatabase' => array('index' => '', 'version' => ''),
            'aDatabases' => DBRepository::getDatabases(),
            'sErrorMessage' => isset($_GET['e']) ? $_GET['e'] : '',
        ));
    }

    // action get_commits (ajax)
    public function getCommits(Request $request, Application $app) {
        $sDatabaseName = $app['request']->get('database_name');

        try {
            $aCommits = DBRepository::getCommits();
        } catch (Exception $oException) {
            return $app->json(array(
                'status' => 0,
                'message' => $oException->getMessage(),
            ));
        }

        return $app->json(array(
            'status' => 1
        ) + $aCommits);
    }

    // action checkout (ajax)
    public function checkout(Request $request, Application $app) {
        try {
            $aResult = DBRepository::checkout($app['request']->get('hash'));
        } catch (Exception $e) {
            return $app->json(array(
                'status' => 0,
                'message' => $e->getMessage(),
            ));
        }

        $aResult['status'] = 1;
        return $app->json($aResult);
    }

    // action view_diff (html)
    public function viewDiff(Request $request, Application $app) {
        $sSchemaName = $app['request']->get('schema_name');
        $sObjectIndex = $app['request']->get('object_index');
        $sFilename = $app['request']->get('file_name');

        return $app['twig']->render('/view_diff.haml', array(
            'aDiff' => DBRepository::getDiffAsHTML($sSchemaName, $sObjectIndex, $sFilename),
        ));
    }

    // action apply (ajax)
    public function apply(Request $request, Application $app) {
        try {
            $aResult = DBRepository::apply($app['request']->get('objects'), (boolean)$app['request']->get('imitate'));
            $aResult['status'] = 1;
        } catch (Exception $e) {
            $aResult['status'] = 0;
            $aResult['message'] = $e->getMessage() . "\nat " . (string)DBRepository::getLastAppliedObject();
        }

        return $app->json($aResult);
    }

    // action describe (html)
    public function describe(Request $request, Application $app) {
        $sSchemaName = $app['request']->get('schema_name');
        $sObjectIndex = $app['request']->get('object_index');
        $sFilename = $app['request']->get('file_name');

        $sDescription = DBRepository::describe($sSchemaName, $sObjectIndex, $sFilename);

        if ($app['request']->get('action') == 'download' and $sDescription) {
            $sFileName = sys_get_temp_dir() . '/' . $sFilename . '.sql';
            file_put_contents($sFileName, $sDescription);
            return $app->sendFile($sFileName, 200, array('Content-type' => 'text/sql'), 'attachment');
        }

        return $app['twig']->render('/describe.haml', array(
            'sDescription' => $sDescription,
        ));
    }

}
