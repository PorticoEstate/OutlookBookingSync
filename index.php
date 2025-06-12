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

// Register logger service (keep this as it's used by multiple services)
$container->set('logger', function () {
	$logger = new \Monolog\Logger('outlook_sync');
	$handler = new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info);
	$logger->pushHandler($handler);
	return $logger;
});

// Register controllers in the container
$container->set(\App\Controller\SyncController::class, function () {
	return new \App\Controller\SyncController();
});

$container->set(\App\Controller\SyncMappingController::class, function () {
	return new \App\Controller\SyncMappingController();
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Add error handling middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Register API key middleware globally
$app->add(ApiKeyMiddleware::class);

// Middleware to inject db and logger objects into requests
$app->add(function ($request, $handler) use ($container) {
    $db = $container->get('db');
    $logger = $container->get('logger');
    $request = $request->withAttribute('db', $db);
    $request = $request->withAttribute('logger', $logger);
    return $handler->handle($request);
});

// Register routes
$app->get('/resource-mapping', [\App\Controller\ResourceMappingController::class, 'getMapping']);
$app->get('/outlook/available-rooms', [\App\Controller\OutlookController::class, 'getAvailableRooms']);
$app->get('/outlook/available-groups', [\App\Controller\OutlookController::class, 'getAvailableGroups']);
$app->get('/outlook/users/{userId}/calendar-items', [\App\Controller\OutlookController::class, 'getUserCalendarItems']);

// Sync mapping routes
$app->post('/sync/populate-mapping', [\App\Controller\SyncMappingController::class, 'populateMapping']);
$app->get('/sync/populate-mapping', [\App\Controller\SyncMappingController::class, 'populateMapping']);
$app->get('/sync/pending-items', [\App\Controller\SyncMappingController::class, 'getPendingItems']);
$app->delete('/sync/cleanup-orphaned', [\App\Controller\SyncMappingController::class, 'cleanupOrphaned']);
$app->get('/sync/stats', [\App\Controller\SyncMappingController::class, 'getStats']);

// Outlook sync routes
$app->post('/sync/to-outlook', [\App\Controller\SyncController::class, 'syncToOutlook']);
$app->post('/sync/item/{reservationType}/{reservationId}/{resourceId}', [\App\Controller\SyncController::class, 'syncSpecificItem']);
$app->get('/sync/status', [\App\Controller\SyncController::class, 'getSyncStatus']);

// Reverse sync routes (Outlook → Booking System)
$app->get('/sync/outlook-events', [\App\Controller\SyncController::class, 'getOutlookEvents']);
$app->post('/sync/from-outlook', [\App\Controller\SyncController::class, 'populateFromOutlook']);

// Booking system integration routes
$app->post('/booking/process-imports', [\App\Controller\BookingSystemController::class, 'processImportedEvents']);
$app->get('/booking/processing-stats', [\App\Controller\BookingSystemController::class, 'getProcessingStats']);
$app->get('/booking/pending-imports', [\App\Controller\BookingSystemController::class, 'getPendingImports']);
$app->get('/booking/processed-imports', [\App\Controller\BookingSystemController::class, 'getProcessedImports']);

$app->run();
