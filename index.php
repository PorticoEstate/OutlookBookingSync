<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Dotenv\Dotenv;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\TenantResolverMiddleware;
use App\Middleware\AdminRoleMiddleware;
use App\Middleware\CsrfMiddleware;
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
        strpos($requestUri, '/bridges') === 0 ||
        strpos($requestUri, '/health') === 0 ||
        strpos($requestUri, '/mappings') === 0 ||
        strpos($requestUri, '/alerts') === 0 ||
        strpos($requestUri, '/webhook') === 0 ||
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

// Register SyncLogService
$container->set('syncLog', function () use ($container)
{
    return new \App\Services\SyncLogService($container->get('db'));
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

$container->set(\App\Controller\MaintenanceController::class, function () use ($container)
{
    return new \App\Controller\MaintenanceController($container->get('db'), $container->get('logger'), $container->get('bridgeManager'));
});

$container->set(\App\Controller\AdminController::class, function () use ($container)
{
    return new \App\Controller\AdminController($container->get('db'));
});

$container->set(\App\Controller\MigrationController::class, function () use ($container)
{
    return new \App\Controller\MigrationController($container->get('db'));
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Register API key middleware globally
// NOTE: Slim applies middleware in LIFO order; add TenantResolver last so it runs first.
// Admin protections (run earliest)
$app->add(new AdminRoleMiddleware());
$app->add(new CsrfMiddleware());
// Auth then tenant resolution (tenant must run before auth at runtime)
$app->add(ApiKeyMiddleware::class);
$app->add(TenantResolverMiddleware::class);

// Middleware to inject db and logger objects into requests
$app->add(function ($request, $handler) use ($container)
{
    $db = $container->get('db');
    $logger = $container->get('logger');
    $request = $request->withAttribute('db', $db);
    $request = $request->withAttribute('logger', $logger);
    return $handler->handle($request);
});

// Ensure routing executes before auth middleware by adding it after them (LIFO -> runs earlier)
$app->addRoutingMiddleware();

// Add error handling middleware last so it wraps everything (and catches routing errors)
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Register routes

// Legacy resource mapping route removed (use /mappings/resources instead)

// Generic bridge-based resource discovery routes (replaces Outlook-specific endpoints)

// Get available resources for a specific bridge
$app->get('/bridges/{bridgeName}/available-resources', [\App\Controller\BridgeController::class, 'getAvailableResources']);

// Get available groups/collections for a specific bridge  
$app->get('/bridges/{bridgeName}/available-groups', [\App\Controller\BridgeController::class, 'getAvailableGroups']);

// Get calendar items for a specific resource on a bridge
$app->get('/bridges/{bridgeName}/resources/{resourceId}/calendar-items', [\App\Controller\BridgeController::class, 'getResourceCalendarItems']);

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

// Maintenance routes
$app->post('/maintenance/cleanup-logs', [\App\Controller\MaintenanceController::class, 'cleanupLogs']);
// Renew expiring webhook subscriptions
$app->post('/maintenance/renew-subscriptions', [\App\Controller\MaintenanceController::class, 'renewSubscriptions']);

// CSRF token endpoint (GET only) - creates/returns token in session
$app->get('/admin/csrf', function (Request $request, Response $response) {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    $response->getBody()->write(json_encode(['csrf_token' => $_SESSION['csrf_token']], JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Admin API routes (CRUD tenants, rotate keys, manage configs)
$app->group('/admin', function ($group) {
    $group->get('/tenants', [\App\Controller\AdminController::class, 'listTenants']);
    $group->post('/tenants', [\App\Controller\AdminController::class, 'createTenant']);
    $group->get('/tenants/{tenantId}', [\App\Controller\AdminController::class, 'getTenant']);
    $group->put('/tenants/{tenantId}', [\App\Controller\AdminController::class, 'updateTenant']);
    $group->delete('/tenants/{tenantId}', [\App\Controller\AdminController::class, 'deleteTenant']);
    $group->post('/tenants/{tenantId}/keys/rotate', [\App\Controller\AdminController::class, 'rotateApiKey']);
    $group->get('/tenants/{tenantId}/keys/metadata', [\App\Controller\AdminController::class, 'getKeyMetadata']);
    $group->put('/tenants/{tenantId}/configs/{bridgeName}', [\App\Controller\AdminController::class, 'upsertBridgeConfig']);
    $group->get('/tenants/{tenantId}/configs/{bridgeName}', [\App\Controller\AdminController::class, 'getBridgeConfig']);
});

// Migration management API routes (admin access required)
$app->group('/api/migrations', function ($group) {
    $group->get('/status', [\App\Controller\MigrationController::class, 'getStatus']);
    $group->post('/run', [\App\Controller\MigrationController::class, 'runMigration']);
    $group->post('/run-all', [\App\Controller\MigrationController::class, 'runAllMigrations']);
    $group->get('/{version}/content', [\App\Controller\MigrationController::class, 'getMigrationContent']);
    $group->post('/create', [\App\Controller\MigrationController::class, 'createMigration']);
});

// Dashboard route now handled by .htaccess directly serving public/dashboard.html

// Register Bridge Manager and related services
$container->set('bridgeManager', function () use ($container)
{
    $manager = new \App\Services\BridgeManager($container->get('logger'), $container->get('db'), $container->get('syncLog'));

    // Register Outlook bridge
    $manager->registerBridge('outlook', \App\Bridge\OutlookBridge::class, [
    ]);

    // Register Booking System bridge
    $manager->registerBridge('booking_system', \App\Bridge\BookingSystemBridge::class, [
        'throw_on_api_failure' => $_ENV['BOOKING_SYSTEM_THROW_ON_FAILURE'] ?? true
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

$container->set(\App\Controller\BridgeResourceController::class, function () use ($container)
{
    return new \App\Controller\BridgeResourceController(
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
// GET alias for Microsoft Graph validation (validationToken)
$app->get('/bridges/webhook/{bridgeName}', [\App\Controller\BridgeController::class, 'handleWebhook']);

// Create webhook subscriptions for a bridge
$app->post('/bridges/{bridgeName}/subscriptions', [\App\Controller\BridgeController::class, 'createSubscriptions']);

// List webhook subscriptions for a bridge
$app->get('/bridges/{bridgeName}/subscriptions', [\App\Controller\BridgeController::class, 'listSubscriptions']);

// Delete a webhook subscription
$app->delete('/bridges/{bridgeName}/subscriptions/{subscriptionId}', [\App\Controller\BridgeController::class, 'deleteSubscription']);

// Event management routes
$app->post('/bridges/{bridgeName}/resources/{resourceId}/events', [\App\Controller\BridgeController::class, 'createEvent']);
$app->put('/bridges/{bridgeName}/events/{eventId}', [\App\Controller\BridgeController::class, 'updateEvent']);
$app->delete('/bridges/{bridgeName}/events/{eventId}', [\App\Controller\BridgeController::class, 'deleteEvent']);

// Get health status of all bridges
$app->get('/bridges/health', [\App\Controller\BridgeController::class, 'getHealthStatus']);

// Session diagnostics for debugging
$app->get('/bridges/{bridgeName}/session-debug', [\App\Controller\BridgeController::class, 'getSessionDiagnostics']);

// Manual deletion sync
$app->post('/bridges/sync-deletions', [\App\Controller\BridgeController::class, 'syncDeletions']);

// Process deletion check queue
$app->post('/bridges/process-deletion-queue', [\App\Controller\BridgeController::class, 'processDeletionQueue']);

// Process webhook queue (bridge_sync queue items)
$app->post('/bridges/process-webhook-queue', [\App\Controller\BridgeController::class, 'processWebhookQueue']);

// Resource Mapping API Routes

// Get all resource mappings
$app->get('/mappings/resources', [\App\Controller\ResourceMappingController::class, 'getResourceMappings']);

// Create new resource mapping
$app->post('/mappings/resources', [\App\Controller\ResourceMappingController::class, 'createResourceMapping']);

// Update existing resource mapping
$app->put('/mappings/resources/{id}', [\App\Controller\ResourceMappingController::class, 'updateResourceMapping']);

// (Deprecated) Delete by composite key route removed; use /mappings/resources/by-key/... instead

// Get resource mapping by booking system resource ID
$app->get('/mappings/resources/by-resource/{source_calendar_id}', [\App\Controller\ResourceMappingController::class, 'getResourceMappingByResource']);

// Trigger sync for specific resource mapping
$app->post('/mappings/resources/{id}/sync', [\App\Controller\ResourceMappingController::class, 'syncResourceMapping']);

// Add this route for deleting by composite key
$app->delete('/mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id}', [\App\Controller\ResourceMappingController::class, 'deleteResourceMappingByKey']);

// Bridge Resources API Routes (Admin)
$app->group('/admin/resources', function ($group) {
    $group->get('', [\App\Controller\BridgeResourceController::class, 'listResources']);
    $group->post('', [\App\Controller\BridgeResourceController::class, 'createResource']);
    $group->put('/{id}', [\App\Controller\BridgeResourceController::class, 'updateResource']);
    $group->delete('/{id}', [\App\Controller\BridgeResourceController::class, 'deleteResource']);
    $group->post('/import', [\App\Controller\BridgeResourceController::class, 'importFromCSV']);
    $group->get('/stats', [\App\Controller\BridgeResourceController::class, 'getStats']);
});


// Sync Status Management Routes (added for comprehensive sync_status support)
// IMPORTANT: These routes must come before the catch-all 404 route

// Get detailed sync status for monitoring
$app->get('/health/sync-status', [\App\Controller\HealthController::class, 'getSyncStatusDetails']);

// Get queue statistics for dashboard monitoring
$app->get('/health/queue-stats', [\App\Controller\HealthController::class, 'getQueueStats']);

// Process pending syncs for specific bridge or all bridges
$app->post('/bridges/process-pending-syncs[/{bridgeName}]', [\App\Controller\BridgeController::class, 'processPendingSyncs']);

// Re-enable failed events for specific bridge or all bridges
$app->post('/bridges/re-enable-failed[/{bridgeName}]', [\App\Controller\BridgeController::class, 'reEnableFailedEvents']);

// Get sync statistics for specific bridge or all bridges
$app->get('/bridges/sync-stats[/{bridgeName}]', [\App\Controller\BridgeController::class, 'getSyncStats']);

// Get cancelled events for cleanup for specific bridge or all bridges
$app->get('/bridges/cancelled-events[/{bridgeName}]', [\App\Controller\BridgeController::class, 'getCancelledEvents']);

// Custom 404 handler with helpful JSON responses for API endpoints
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

// Add custom 404 handler
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response)
{
    $uri = $request->getUri()->getPath();
    $method = $request->getMethod();

    // Return helpful JSON response for API endpoints (with query/body parameter hints)
    $errorResponse = [
        'error' => 'Not Found',
        'message' => "The endpoint '{$method} {$uri}' was not found",
        'status_code' => 404,
        'timestamp' => date('c'),
        'available_endpoints' => [
            'admin' => [
                'GET /admin/csrf' => 'Get CSRF token for admin UI (returns {csrf_token})',
                'GET /admin/tenants' => 'List tenants',
                'POST /admin/tenants' => 'Create tenant',
                'GET /admin/tenants/{tenantId}' => 'Get tenant by id',
                'PUT /admin/tenants/{tenantId}' => 'Update tenant',
                'DELETE /admin/tenants/{tenantId}' => 'Delete tenant',
                'POST /admin/tenants/{tenantId}/keys/rotate' => 'Rotate per-tenant API key (plaintext returned once)',
                'GET /admin/tenants/{tenantId}/keys/metadata' => 'Get key metadata (created_at)',
                'PUT /admin/tenants/{tenantId}/configs/{bridgeName}' => 'Upsert per-tenant bridge config JSON',
                'GET /admin/tenants/{tenantId}/configs/{bridgeName}' => 'Get per-tenant bridge config JSON'
            ],
            'bridge_operations' => [
                'GET /bridges' => 'List all available bridges',
                'GET /bridges/{bridge}/calendars' => 'Get calendars for specific bridge (optional query: ?limit=int&offset=int)',
                'GET /bridges/{bridge}/available-resources' => 'Get available resources (rooms/equipment) for bridge (query: ?query=string&limit=int&offset=int)',
                'GET /bridges/{bridge}/available-groups' => 'Get available groups/collections for bridge (query: ?query=string&limit=int&offset=int)',
                'GET /bridges/{bridge}/resources/{resourceId}/calendar-items' => 'Get calendar items for specific resource on bridge (query: ?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&limit=int&offset=int)',
                'POST /bridges/sync/{source}/{target}' => 'Sync events between bridges (body/query: start_date=YYYY-MM-DD, end_date=YYYY-MM-DD, optional: pair_id, source_calendar_id, target_calendar_id, handle_deletions, skip_updates, dry_run, sync_method)',
                'POST /bridges/webhook/{bridge}' => 'Handle bridge webhooks (body format varies by bridge - see docs for booking_system webhook example)',
                'POST /bridges/{bridge}/subscriptions' => 'Create webhook subscriptions for a bridge (body: webhook_url, optional: calendar_ids[])',
                'GET /bridges/{bridge}/subscriptions' => 'List webhook subscriptions (query: ?search=string&status=active|expired|expiring&limit=int&offset=int&stats_only=bool)',
                'DELETE /bridges/{bridge}/subscriptions/{subscriptionId}' => 'Delete a webhook subscription',
                'POST /bridges/process-webhook-queue' => 'Process webhook queue (bridge_sync queue items) (optional body: batch_size=int)',
                'POST /bridges/process-deletion-queue' => 'Process deletion queue (optional body: batch_size=int)',
                'POST /bridges/sync-deletions' => 'Sync deletions across bridges',
                'GET /bridges/health' => 'Get health status of all bridges'
            ],
            'sync_status_management' => [
                'GET /health/sync-status' => 'Get detailed sync status monitoring (optional query: ?status=failed|pending|completed&limit=int&offset=int)',
                'GET /health/queue-stats' => 'Get queue statistics for dashboard monitoring',
                'POST /bridges/process-pending-syncs' => 'Process pending syncs (all bridges) (body: batch_size=int)',
                'POST /bridges/process-pending-syncs/{bridge}' => 'Process pending syncs for specific bridge (body: batch_size=int)',
                'POST /bridges/re-enable-failed' => 'Re-enable failed events (all bridges)',
                'POST /bridges/re-enable-failed/{bridge}' => 'Re-enable failed events for specific bridge',
                'GET /bridges/sync-stats' => 'Get sync statistics (all bridges) (optional query: ?from=YYYY-MM-DD&to=YYYY-MM-DD)',
                'GET /bridges/sync-stats/{bridge}' => 'Get sync statistics for specific bridge (optional query: ?from=YYYY-MM-DD&to=YYYY-MM-DD)',
                'GET /bridges/cancelled-events' => 'Get cancelled events (all bridges) (optional query: ?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=int&offset=int)',
                'GET /bridges/cancelled-events/{bridge}' => 'Get cancelled events for specific bridge (optional query: ?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=int&offset=int)',
                'GET /bridges/{bridge}/pending-events' => 'Get pending sync events for specific bridge (optional query: ?limit=int&offset=int)'
            ],
            'health_monitoring' => [
                'GET /health' => 'System health check',
                'GET /health/system' => 'Detailed system status',
                'GET /health/dashboard' => 'Dashboard data (JSON)'
            ],
            'static_assets' => [
                'GET /dashboard' => 'Monitoring dashboard (HTML) - served directly by Apache',
                'GET /css/{filename}' => 'CSS files - served directly by Apache',
                'GET /js/{filename}' => 'JavaScript files - served directly by Apache',
                'GET /favicon.ico' => 'Favicon - handled by Apache (204 No Content)',
                'Note' => 'Static files are served directly by Apache via .htaccess for better performance'
            ],
            'resource_management' => [
                'GET /mappings/resources' => 'List resource mappings (query: ?limit=int&offset=int)',
                'POST /mappings/resources' => 'Create resource mapping (body: bridge_from, source_calendar_id, target_calendar_id, sync_direction, optional: bridge_pair_id, is_active, sync_enabled)',
                'PUT /mappings/resources/{id}' => 'Update resource mapping (body: fields to update)',
                'DELETE /mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id}' => 'Delete resource mapping by composite key',
                'GET /mappings/resources/by-resource/{source_calendar_id}' => 'Get resource mapping by booking system resource ID',
                'POST /mappings/resources/{id}/sync' => 'Trigger sync for specific resource mapping (optional body: start_date=YYYY-MM-DD, end_date=YYYY-MM-DD, dry_run=bool)'
            ],
            'alerts' => [
                'POST /alerts/check' => 'Check system alerts',
                'GET /alerts' => 'Get active alerts (query: ?limit=int&offset=int)',
                'GET /alerts/stats' => 'Get alert statistics (optional query: ?from=YYYY-MM-DD&to=YYYY-MM-DD)',
                'POST /alerts/{id}/acknowledge' => 'Acknowledge an alert by ID',
                'DELETE /alerts/old' => 'Clear old alerts (optional query: ?before=YYYY-MM-DD)'
            ],
            'maintenance' => [
                'POST /maintenance/cleanup-logs' => 'Cleanup old sync logs (optional query: ?days=int, default 30)',
                'POST /maintenance/renew-subscriptions' => 'Renew expiring webhook subscriptions (query: ?bridge=outlook&renew_before_minutes=int&limit=int)'
            ]
        ],
        'documentation' => 'See doc/api_endpoints.md for complete API documentation'
    ];

    $response->getBody()->write(json_encode($errorResponse, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
})->setName('catch_all_404');

$app->run();
