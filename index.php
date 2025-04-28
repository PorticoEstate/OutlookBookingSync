<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Dotenv\Dotenv;
use App\Middleware\ApiKeyMiddleware;


// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Set up DI container
$container = new Container();

// Register PDO as a shared service
$container->set('db', function ()
{
	$host = $_ENV['DB_HOST'];
	$port = $_ENV['DB_PORT'];
	$dbname = $_ENV['DB_NAME'];
	$user = $_ENV['DB_USER'];
	$pass = $_ENV['DB_PASS'];
	$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
	$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_PERSISTENT => true // persistent connection for transaction state
	];
	return new PDO($dsn, $user, $pass, $options);
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Add error handling middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Register API key middleware globally
$app->add(ApiKeyMiddleware::class);

// Middleware to inject db object into requests
$app->add(function ($request, $handler) use ($container) {
    $db = $container->get('db');
    $request = $request->withAttribute('db', $db);
    return $handler->handle($request);
});

// Register routes
$app->get('/resource-mapping', [\App\Controller\ResourceMappingController::class, 'getMapping']);

$app->run();
