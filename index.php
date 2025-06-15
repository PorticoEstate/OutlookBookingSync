<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Dotenv\Dotenv;
use App\Middleware\ApiKeyMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Load environment variables with friendly error handling
try
{
	$dotenv = Dotenv::createImmutable(__DIR__);
	$dotenv->load();
}
catch (Throwable $e)
{
	// Load the template for environment configuration error
	require_once __DIR__ . '/src/Services/TemplateLoader.php';
	
	$templateLoader = new TemplateLoader();
	$envExampleExists = file_exists(__DIR__ . '/.env.example');

	$templateVariables = [
		'env_example_message' => $envExampleExists ? 
			"Copy the example file: <code>cp .env.example .env</code>" : 
			"Create a new <code>.env</code> file in the project root",
		'env_example_status' => $envExampleExists ? "
			<div class='success'>
				<strong>✅ Found .env.example</strong><br>
				A template file is available. Copy it to <code>.env</code> and customize the values.
			</div>" : "",
		'error_message' => htmlspecialchars($e->getMessage()),
		'error_file' => htmlspecialchars($e->getFile()),
		'error_line' => $e->getLine()
	];

	echo $templateLoader->render('setup', $templateVariables);
	exit(1);
}

// Set up DI container
$container = new Container();

// Register PDO as a shared service with error handling
$container->set('db', function ()
{
	try
	{
		// Use PostgreSQL from environment variables
		$host = $_ENV['DB_HOST'] ?? 'localhost';
		$port = $_ENV['DB_PORT'] ?? '5432';
		$dbname = $_ENV['DB_NAME'] ?? 'calendar_bridge';
		$username = $_ENV['DB_USER'] ?? 'bridge_user';
		$password = $_ENV['DB_PASS'] ?? 'bridge_password';

		$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];

		$pdo = new PDO($dsn, $username, $password, $options);

		// Test connection
		$pdo->query('SELECT 1');

		return $pdo;
	}
	catch (PDOException $e)
	{
		error_log("Database connection failed: " . $e->getMessage());
		// For dashboard/health endpoints, we can return null and handle gracefully
		return null;
	}
});

// Register logger service (keep this as it's used by multiple services)
$container->set('logger', function ()
{
	$logger = new \Monolog\Logger('outlook_sync');
	$handler = new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info);
	$logger->pushHandler($handler);
	return $logger;
});

// Register controllers in the container

$container->set(\App\Controller\HealthController::class, function () use ($container)
{
	return new \App\Controller\HealthController($container->get('db'), $container->get('logger'));
});

$container->set(\App\Controller\AlertController::class, function () use ($container)
{
	return new \App\Controller\AlertController($container->get('db'), $container->get('logger'));
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Add error handling middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Register API key middleware globally
$app->add(ApiKeyMiddleware::class);

// Middleware to inject db and logger objects into requests
$app->add(function ($request, $handler) use ($container)
{
	$db = $container->get('db');
	$logger = $container->get('logger');
	$request = $request->withAttribute('db', $db);
	$request = $request->withAttribute('logger', $logger);
	return $handler->handle($request);
});

// Register routes

// Get resource to Outlook calendar mapping information (legacy)
$app->get('/resource-mapping', [\App\Controller\ResourceMappingController::class, 'getMapping']);

// Get available Outlook room calendars
$app->get('/outlook/available-rooms', [\App\Controller\OutlookController::class, 'getAvailableRooms']);

// Get available Outlook distribution groups
$app->get('/outlook/available-groups', [\App\Controller\OutlookController::class, 'getAvailableGroups']);

// Get calendar items for a specific user
$app->get('/outlook/users/{userId}/calendar-items', [\App\Controller\OutlookController::class, 'getUserCalendarItems']);

// Bridge-compatible booking system integration routes (replaces legacy routes)

// Process pending bridge sync operations (replaces /booking/process-imports)
$app->post('/bridge/process-pending', [\App\Controller\BridgeBookingController::class, 'processPendingSyncs']);

// Get bridge processing statistics (replaces /booking/processing-stats)  
$app->get('/bridge/stats', [\App\Controller\BridgeBookingController::class, 'getBridgeStats']);

// Get pending bridge operations (replaces /booking/pending-imports)
$app->get('/bridge/pending', [\App\Controller\BridgeBookingController::class, 'getPendingOperations']);

// Get completed bridge operations (replaces /booking/processed-imports)
$app->get('/bridge/completed', [\App\Controller\BridgeBookingController::class, 'getCompletedOperations']);

// Legacy cancellation routes moved to obsolete
// Use bridge deletion endpoints instead:
// - DELETE /bridges/mappings/{id} - Remove specific bridge mapping
// - POST /bridges/sync-deletions - Process deletion queue  
// - POST /bridges/process-deletion-queue - Process webhook deletions
// - GET /bridges/health - Check bridge status including deletions

// Health monitoring and dashboard routes

// Quick health check for load balancers
$app->get('/health', [\App\Controller\HealthController::class, 'getQuickHealth']);

// Comprehensive system health check
$app->get('/health/system', [\App\Controller\HealthController::class, 'getSystemHealth']);

// Dashboard data endpoint
$app->get('/health/dashboard', [\App\Controller\HealthController::class, 'getDashboardData']);

// Alert monitoring routes

// Run alert checks
$app->post('/alerts/check', [\App\Controller\AlertController::class, 'runAlertChecks']);

// Get recent alerts
$app->get('/alerts', [\App\Controller\AlertController::class, 'getRecentAlerts']);

// Get alert statistics
$app->get('/alerts/stats', [\App\Controller\AlertController::class, 'getAlertStats']);

// Acknowledge an alert
$app->post('/alerts/{id}/acknowledge', [\App\Controller\AlertController::class, 'acknowledgeAlert']);

// Clear old alerts
$app->delete('/alerts/old', [\App\Controller\AlertController::class, 'clearOldAlerts']);

// Serve the monitoring dashboard HTML
$app->get('/dashboard', function (Request $request, Response $response, $args)
{
	$dashboardPath = __DIR__ . '/public/dashboard.html';
	if (file_exists($dashboardPath))
	{
		$response->getBody()->write(file_get_contents($dashboardPath));
		return $response->withHeader('Content-Type', 'text/html');
	}
	else
	{
		$response->getBody()->write('<h1>Dashboard Not Found</h1><p>The monitoring dashboard is not available.</p>');
		return $response->withHeader('Content-Type', 'text/html')->withStatus(404);
	}
});

// Register Bridge Manager and related services
$container->set('bridgeManager', function () use ($container)
{
	$manager = new \App\Services\BridgeManager($container->get('logger'), $container->get('db'));

	// Register Outlook bridge
	$manager->registerBridge('outlook', \App\Bridge\OutlookBridge::class, [
		'client_id' => $_ENV['OUTLOOK_CLIENT_ID'],
		'client_secret' => $_ENV['OUTLOOK_CLIENT_SECRET'],
		'tenant_id' => $_ENV['OUTLOOK_TENANT_ID'],
		'group_id' => $_ENV['OUTLOOK_GROUP_ID'] ?? null
	]);

	// Register Booking System bridge
	$manager->registerBridge('booking_system', \App\Bridge\BookingSystemBridge::class, [
		'api_base_url' => $_ENV['BOOKING_SYSTEM_API_URL'] ?? 'http://localhost',
		'api_key' => $_ENV['BOOKING_SYSTEM_API_KEY'] ?? null
	]);

	return $manager;
});

$container->set(\App\Controller\BridgeController::class, function () use ($container)
{
	return new \App\Controller\BridgeController(
		$container->get('bridgeManager'),
		$container->get('logger'),
		$container->get('db')
	);
});

$container->set(\App\Controller\ResourceMappingController::class, function () use ($container)
{
	return new \App\Controller\ResourceMappingController(
		$container->get('db')
	);
});

$container->set(\App\Controller\BridgeBookingController::class, function () use ($container)
{
	return new \App\Controller\BridgeBookingController(
		$container->get('bridgeManager'),
		$container->get('logger'),
		$container->get('db')
	);
});

// Generic Bridge API Routes

// List all available bridges
$app->get('/bridges', [\App\Controller\BridgeController::class, 'listBridges']);

// Get calendars for a specific bridge
$app->get('/bridges/{bridgeName}/calendars', [\App\Controller\BridgeController::class, 'getCalendars']);

// Sync between two bridges
$app->post('/bridges/sync/{sourceBridge}/{targetBridge}', [\App\Controller\BridgeController::class, 'syncBridges']);

// Handle webhook from any bridge
$app->post('/bridges/webhook/{bridgeName}', [\App\Controller\BridgeController::class, 'handleWebhook']);

// Create webhook subscriptions for a bridge
$app->post('/bridges/{bridgeName}/subscriptions', [\App\Controller\BridgeController::class, 'createSubscriptions']);

// Get health status of all bridges
$app->get('/bridges/health', [\App\Controller\BridgeController::class, 'getHealthStatus']);

// Manual deletion sync
$app->post('/bridges/sync-deletions', [\App\Controller\BridgeController::class, 'syncDeletions']);

// Process deletion check queue
$app->post('/bridges/process-deletion-queue', [\App\Controller\BridgeController::class, 'processDeletionQueue']);

// Resource Mapping API Routes

// Get all resource mappings
$app->get('/mappings/resources', [\App\Controller\ResourceMappingController::class, 'getResourceMappings']);

// Create new resource mapping
$app->post('/mappings/resources', [\App\Controller\ResourceMappingController::class, 'createResourceMapping']);

// Update existing resource mapping
$app->put('/mappings/resources/{id}', [\App\Controller\ResourceMappingController::class, 'updateResourceMapping']);

// Delete resource mapping
$app->delete('/mappings/resources/{id}', [\App\Controller\ResourceMappingController::class, 'deleteResourceMapping']);

// Get resource mapping by booking system resource ID
$app->get('/mappings/resources/by-resource/{resourceId}', [\App\Controller\ResourceMappingController::class, 'getResourceMappingByResource']);

// Trigger sync for specific resource mapping
$app->post('/mappings/resources/{id}/sync', [\App\Controller\ResourceMappingController::class, 'syncResourceMapping']);

// Backwards compatibility routes (redirect to bridge endpoints)
$app->get('/webhook/outlook-notifications', function (Request $request, Response $response, $args) use ($container)
{
	// Redirect Outlook webhooks to bridge webhook handler
	$bridgeController = $container->get(\App\Controller\BridgeController::class);
	$request = $request->withAttribute('bridgeName', 'outlook');
	return $bridgeController->handleWebhook($request, $response, ['bridgeName' => 'outlook']);
});

$app->post('/webhook/outlook-notifications', function (Request $request, Response $response, $args) use ($container)
{
	// Redirect Outlook webhooks to bridge webhook handler
	$bridgeController = $container->get(\App\Controller\BridgeController::class);
	return $bridgeController->handleWebhook($request, $response, ['bridgeName' => 'outlook']);
});

// Custom 404 handler with helpful information
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response)
{
	$requestedPath = $request->getUri()->getPath();
	$method = $request->getMethod();

	$response->getBody()->write("
    <!DOCTYPE html>
    <html>
    <head>
        <title>Calendar Bridge Service - Route Not Found</title>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                max-width: 800px; margin: 50px auto; padding: 20px; 
                background: #f5f5f5; color: #333; line-height: 1.6;
            }
            .container { 
                background: white; padding: 40px; border-radius: 12px; 
                box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
            }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; padding: 20px; margin: -40px -40px 30px -40px; 
                border-radius: 12px 12px 0 0; text-align: center; 
            }
            .error { background: #fee; border-left: 4px solid #e53e3e; padding: 15px; margin: 20px 0; }
            .info { background: #e6f3ff; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0; }
            code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
            .routes { columns: 2; column-gap: 30px; }
            .route-group { break-inside: avoid; margin-bottom: 20px; }
            .route-group h4 { color: #667eea; margin-bottom: 10px; }
            .route { font-family: monospace; font-size: 0.9rem; margin: 5px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🌉 Calendar Bridge Service</h1>
                <p>Route Not Found</p>
            </div>
            
            <div class='error'>
                <strong>404 - Route Not Found</strong><br>
                The requested path <code>$method $requestedPath</code> was not found.
            </div>
            
            <div class='info'>
                <strong>💡 Available Routes</strong><br>
                Here are the main endpoints you can use:
            </div>
            
            <div class='routes'>
                <div class='route-group'>
                    <h4>🏥 Health & Monitoring</h4>
                    <div class='route'>GET /health</div>
                    <div class='route'>GET /health/system</div>
                    <div class='route'>GET /health/dashboard</div>
                    <div class='route'>GET /dashboard</div>
                </div>
                
                <div class='route-group'>
                    <h4>🌉 Bridge Management</h4>
                    <div class='route'>GET /bridges</div>
                    <div class='route'>GET /bridges/{name}/calendars</div>
                    <div class='route'>POST /bridges/sync/{source}/{target}</div>
                    <div class='route'>GET /bridges/health</div>
                </div>
                
                <div class='route-group'>
                    <h4>🗑️ Deletion & Cancellation</h4>
                    <div class='route'>POST /bridges/sync-deletions</div>
                    <div class='route'>POST /bridges/process-deletion-queue</div>
                </div>
                
                <div class='route-group'>
                    <h4>📍 Resource Mapping</h4>
                    <div class='route'>GET /resource-mapping</div>
                    <div class='route'>POST /resource-mapping</div>
                    <div class='route'>PUT /resource-mapping/{id}</div>
                </div>
            </div>
            
            <div class='info'>
                <strong>📚 Documentation</strong><br>
                For complete API documentation, see <code>README_BRIDGE.md</code> or visit the 
                <a href='/dashboard' style='color: #667eea;'>monitoring dashboard</a>.
            </div>
        </div>
    </body>
    </html>");

	return $response->withStatus(404);
});

$app->run();
