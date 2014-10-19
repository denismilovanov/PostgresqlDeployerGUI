<?php

define("VERSION", "0.0.3");

require_once '../lib/vendor/autoload.php';

require_once '../lib/models/DatabaseObject.php';
require_once '../lib/models/Table.php';
require_once '../lib/models/Seed.php';
require_once '../lib/models/StoredFunction.php';
require_once '../lib/models/Type.php';

require_once '../lib/models/User.php';

require_once '../lib/models/DBRepository.php';
require_once '../lib/models/Database.php';

require_once '../lib/controllers/RepositoryController.php';

use Symfony\Component\Debug\ErrorHandler;

ErrorHandler::register();

// read config/databases.json
DBRepository::readSettings();

$app = new Silex\Application();

$app['debug'] = true;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => '../lib/views',
));

$app->register(new SilexMtHaml\MtHamlServiceProvider());

$app['twig']->addGlobal('VERSION', VERSION);

$app->get('/', 'RepositoryController::redirectToDatabase');

$app->get('/error/{error_type}/', 'RepositoryController::error');

$app->get('/{database_name}/', 'RepositoryController::index')->before('RepositoryController::useDatabase');

$app->match('/{database_name}/login/', 'RepositoryController::login')->before('RepositoryController::useDatabase');
$app->match('/{database_name}/logout/', 'RepositoryController::logout')->before('RepositoryController::useDatabase');

$app->get('/{database_name}/get_commits/', 'RepositoryController::getCommits')->before('RepositoryController::useDatabase');

$app->get('/{database_name}/{hash}/checkout/', 'RepositoryController::checkout')->before('RepositoryController::useDatabase');

$app->get('/{database_name}/{schema_name}/{object_index}/{file_name}/view_diff/', 'RepositoryController::viewDiff')->before('RepositoryController::useDatabase');
$app->get('/{database_name}/{schema_name}/{object_index}/{file_name}/describe/', 'RepositoryController::describe')->before('RepositoryController::useDatabase');
$app->post('/{database_name}/{schema_name}/{object_index}/{file_name}/drop/', 'RepositoryController::drop')->before('RepositoryController::useDatabase');

$app->post('{database_name}/apply/', 'RepositoryController::apply')->before('RepositoryController::useDatabase');

$app->run();



