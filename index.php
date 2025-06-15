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
    // Check if this looks like an API request
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    $isApiRequest = (
        strpos($requestUri, '/api/') === 0 ||
        strpos($requestUri, '/bridges') === 0 ||
        strpos($requestUri, '/health') === 0 ||
        strpos($requestUri, '/resource-mapping') === 0 ||
        strpos($requestUri, '/alerts') === 0 ||
        strpos($requestUri, '/sync') === 0 ||
        strpos($requestUri, '/cancel') === 0 ||
        strpos($requestUri, '/booking') === 0 ||
        strpos($requestUri, '/outlook') === 0 ||
        strpos($acceptHeader, 'application/json') !== false ||
        strpos($contentType, 'application/json') !== false
    );

    if ($isApiRequest)
    {
        // Return JSON error for API requests
        header('Content-Type: application/json');
        http_response_code(500);

        $errorResponse = [
            'error' => 'Configuration Error',
            'message' => 'Environment configuration missing or invalid',
            'details' => $e->getMessage(),
            'status_code' => 500,
            'timestamp' => date('c'),
            'solution' => [
                'step1' => 'Create .env file from .env.example if available',
                'step2' => 'Configure required environment variables (DB, API keys, etc.)',
                'step3' => 'Restart the service after configuration'
            ],
            'required_env_vars' => [
                'DB_HOST',
                'DB_NAME',
                'DB_USER',
                'DB_PASS',
                'API_KEY',
                'APP_BASE_URL',
                'OUTLOOK_CLIENT_ID',
                'OUTLOOK_CLIENT_SECRET',
                'OUTLOOK_TENANT_ID'
            ]
        ];

        echo json_encode($errorResponse, JSON_PRETTY_PRINT);
        exit(1);
    }

    // For non-API requests, load the HTML template
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

// Generic bridge-based resource discovery routes (replaces Outlook-specific endpoints)

// Get available resources for a specific bridge
$app->get('/bridges/{bridgeName}/available-resources', [\App\Controller\BridgeController::class, 'getAvailableResources']);

// Get available groups/collections for a specific bridge  
$app->get('/bridges/{bridgeName}/available-groups', [\App\Controller\BridgeController::class, 'getAvailableGroups']);

// Get calendar items for a specific user/resource on a bridge
$app->get('/bridges/{bridgeName}/users/{userId}/calendar-items', [\App\Controller\BridgeController::class, 'getUserCalendarItems']);

// Backward compatibility routes (redirect to bridge endpoints)
$app->get('/outlook/available-rooms', function (Request $request, Response $response, $args) use ($container) {
    // Redirect to generic bridge endpoint
    $bridgeController = $container->get(\App\Controller\BridgeController::class);
    return $bridgeController->getAvailableResources($request, $response, ['bridgeName' => 'outlook']);
});

$app->get('/outlook/available-groups', function (Request $request, Response $response, $args) use ($container) {
    // Redirect to generic bridge endpoint
    $bridgeController = $container->get(\App\Controller\BridgeController::class);
    return $bridgeController->getAvailableGroups($request, $response, ['bridgeName' => 'outlook']);
});

$app->get('/outlook/users/{userId}/calendar-items', function (Request $request, Response $response, $args) use ($container) {
    // Redirect to generic bridge endpoint
    $bridgeController = $container->get(\App\Controller\BridgeController::class);
    return $bridgeController->getUserCalendarItems($request, $response, $args + ['bridgeName' => 'outlook']);
});

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

// Custom 404 handler with helpful JSON responses for API endpoints
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

// Add custom 404 handler
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response)
{
    $uri = $request->getUri()->getPath();
    $method = $request->getMethod();

    // Return helpful JSON response for API endpoints
    $errorResponse = [
        'error' => 'Not Found',
        'message' => "The endpoint '{$method} {$uri}' was not found",
        'status_code' => 404,
        'timestamp' => date('c'),
        'available_endpoints' => [
            'bridge_operations' => [
                'GET /bridges' => 'List all available bridges',
                'GET /bridges/{bridge}/calendars' => 'Get calendars for specific bridge',
                'GET /bridges/{bridge}/available-resources' => 'Get available resources (rooms/equipment) for bridge',
                'GET /bridges/{bridge}/available-groups' => 'Get available groups/collections for bridge',
                'GET /bridges/{bridge}/users/{userId}/calendar-items' => 'Get calendar items for specific user on bridge',
                'POST /bridges/sync/{source}/{target}' => 'Sync events between bridges',
                'POST /bridges/webhook/{bridge}' => 'Handle bridge webhooks',
                'POST /bridges/process-deletion-queue' => 'Process deletion queue',
                'POST /bridges/sync-deletions' => 'Sync deletions across bridges'
            ],
            'health_monitoring' => [
                'GET /health' => 'System health check',
                'GET /health/system' => 'Detailed system status',
                'GET /health/dashboard' => 'Dashboard data (JSON)',
                'GET /dashboard' => 'Monitoring dashboard (HTML)'
            ],
            'resource_management' => [
                'GET /mappings/resources' => 'List resource mappings',
                'POST /mappings/resources' => 'Create resource mapping',
                'PUT /mappings/resources/{id}' => 'Update resource mapping'
            ],
            'alerts' => [
                'POST /alerts/check' => 'Check system alerts',
                'GET /alerts' => 'Get active alerts'
            ],
            'backward_compatibility' => [
                'GET /outlook/available-rooms' => 'Get Outlook rooms (redirects to /bridges/outlook/available-resources)',
                'GET /outlook/available-groups' => 'Get Outlook groups (redirects to /bridges/outlook/available-groups)',
                'GET /outlook/users/{userId}/calendar-items' => 'Get user calendar (redirects to bridge endpoint)'
            ]
        ],
        'documentation' => 'See README_BRIDGE.md for complete API documentation'
    ];

    $response->getBody()->write(json_encode($errorResponse, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
});

$app->run();
