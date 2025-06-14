<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Dotenv\Dotenv;
use App\Middleware\ApiKeyMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


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
// $container->set(\App\Controller\SyncController::class, function () {
// 	return new \App\Controller\SyncController();
// });

// $container->set(\App\Controller\SyncMappingController::class, function () {
// 	return new \App\Controller\SyncMappingController();
// });

$container->set(\App\Controller\HealthController::class, function () use ($container) {
	return new \App\Controller\HealthController($container->get('db'), $container->get('logger'));
});

$container->set(\App\Controller\AlertController::class, function () use ($container) {
	return new \App\Controller\AlertController($container->get('db'), $container->get('logger'));
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

// Get resource to Outlook calendar mapping information (legacy)
$app->get('/resource-mapping', [\App\Controller\ResourceMappingController::class, 'getMapping']);

// Get available Outlook room calendars
$app->get('/outlook/available-rooms', [\App\Controller\OutlookController::class, 'getAvailableRooms']);

// Get available Outlook distribution groups
$app->get('/outlook/available-groups', [\App\Controller\OutlookController::class, 'getAvailableGroups']);

// Get calendar items for a specific user
$app->get('/outlook/users/{userId}/calendar-items', [\App\Controller\OutlookController::class, 'getUserCalendarItems']);

// Sync mapping routes

// Populate mapping table with booking system items (create sync mappings)
$app->post('/sync/populate-mapping', [\App\Controller\SyncMappingController::class, 'populateMapping']);
$app->get('/sync/populate-mapping', [\App\Controller\SyncMappingController::class, 'populateMapping']);

// Get items pending synchronization to Outlook
$app->get('/sync/pending-items', [\App\Controller\SyncMappingController::class, 'getPendingItems']);

// Clean up orphaned mappings (remove mappings for deleted calendar items)
$app->delete('/sync/cleanup-orphaned', [\App\Controller\SyncMappingController::class, 'cleanupOrphaned']);

// Get comprehensive sync statistics with directional tracking
$app->get('/sync/stats', [\App\Controller\SyncMappingController::class, 'getStats']);

// Outlook sync routes (Booking System → Outlook)

// Sync pending booking system items to Outlook calendars
$app->post('/sync/to-outlook', [\App\Controller\SyncController::class, 'syncToOutlook']);

// Sync a specific booking system item to Outlook
$app->post('/sync/item/{reservationType}/{reservationId}/{resourceId}', [\App\Controller\SyncController::class, 'syncSpecificItem']);

// Get sync status summary (legacy endpoint)
$app->get('/sync/status', [\App\Controller\SyncController::class, 'getSyncStatus']);

// Reverse sync routes (Outlook → Booking System)

// Get Outlook events that aren't in the booking system
$app->get('/sync/outlook-events', [\App\Controller\SyncController::class, 'getOutlookEvents']);

// Import Outlook events to mapping table for processing
$app->post('/sync/from-outlook', [\App\Controller\SyncController::class, 'populateFromOutlook']);

// Booking system integration routes

// Convert imported Outlook events to complete booking system entries
$app->post('/booking/process-imports', [\App\Controller\BookingSystemController::class, 'processImportedEvents']);

// Get statistics about import processing operations
$app->get('/booking/processing-stats', [\App\Controller\BookingSystemController::class, 'getProcessingStats']);

// Get Outlook events awaiting conversion to booking entries
$app->get('/booking/pending-imports', [\App\Controller\BookingSystemController::class, 'getPendingImports']);

// Get successfully processed imports with reservation IDs
$app->get('/booking/processed-imports', [\App\Controller\BookingSystemController::class, 'getProcessedImports']);

// Cancellation routes

// Cancel a specific booking system reservation and its Outlook event
$app->delete('/cancel/reservation/{reservationType}/{reservationId}/{resourceId}', [\App\Controller\CancellationController::class, 'cancelReservation']);

// Cancel a specific Outlook event and update booking system
$app->delete('/cancel/outlook-event/{outlookEventId}', [\App\Controller\CancellationController::class, 'cancelOutlookEvent']);

// Process multiple cancellations in bulk
$app->post('/cancel/bulk', [\App\Controller\CancellationController::class, 'processBulkCancellations']);

// Get comprehensive cancellation statistics
$app->get('/cancel/stats', [\App\Controller\CancellationController::class, 'getCancellationStats']);

// Get list of all cancelled reservations
$app->get('/cancel/cancelled-reservations', [\App\Controller\CancellationController::class, 'getCancelledReservations']);

// Cancellation detection routes

// Automatically detect and process cancelled reservations
$app->post('/cancel/detect', [\App\Controller\CancellationController::class, 'detectCancellations']);

// Automatically detect and process re-enabled reservations (reset to pending for sync)
$app->post('/cancel/detect-reenabled', [\App\Controller\CancellationController::class, 'detectReenabled']);

// Check if a specific reservation is cancelled
$app->get('/cancel/check/{reservationType}/{reservationId}', [\App\Controller\CancellationController::class, 'checkReservationStatus']);

// Get statistics about cancellation detection process
$app->get('/cancel/detection-stats', [\App\Controller\CancellationController::class, 'getDetectionStats']);

// Webhook routes for real-time Outlook change notifications

// Handle incoming webhook notifications from Microsoft Graph
$app->post('/webhook/outlook-notifications', [\App\Controller\WebhookController::class, 'handleOutlookNotification']);

// Create webhook subscriptions for all room calendars
$app->post('/webhook/create-subscriptions', [\App\Controller\WebhookController::class, 'createWebhookSubscriptions']);

// Renew expiring webhook subscriptions
$app->post('/webhook/renew-subscriptions', [\App\Controller\WebhookController::class, 'renewWebhookSubscriptions']);

// Get webhook subscription statistics
$app->get('/webhook/stats', [\App\Controller\WebhookController::class, 'getWebhookStats']);

// Outlook polling routes for change detection (alternative to webhooks)

// Initialize polling state for all room calendars
$app->post('/polling/initialize', [\App\Controller\OutlookPollingController::class, 'initializePolling']);

// Poll all Outlook calendars for changes - main polling endpoint
$app->post('/polling/poll-changes', [\App\Controller\OutlookPollingController::class, 'pollForChanges']);

// Detect missing events by checking if mapped events still exist in Outlook
$app->post('/polling/detect-missing-events', [\App\Controller\OutlookPollingController::class, 'detectMissingEvents']);

// Get polling statistics and health status
$app->get('/polling/stats', [\App\Controller\OutlookPollingController::class, 'getPollingStats']);

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
$app->get('/dashboard', function (Request $request, Response $response, $args) {
    $dashboardPath = __DIR__ . '/public/dashboard.html';
    if (file_exists($dashboardPath)) {
        $response->getBody()->write(file_get_contents($dashboardPath));
        return $response->withHeader('Content-Type', 'text/html');
    } else {
        $response->getBody()->write('<h1>Dashboard Not Found</h1><p>The monitoring dashboard is not available.</p>');
        return $response->withHeader('Content-Type', 'text/html')->withStatus(404);
    }
});

// Register Bridge Manager and related services
$container->set('bridgeManager', function () use ($container) {
    $manager = new \App\Service\BridgeManager($container->get('logger'), $container->get('db'));
    
    // Register Outlook bridge
    $manager->registerBridge('outlook', \App\Bridge\OutlookBridge::class, [
        'client_id' => $_ENV['OUTLOOK_CLIENT_ID'],
        'client_secret' => $_ENV['OUTLOOK_CLIENT_SECRET'],
        'tenant_id' => $_ENV['OUTLOOK_TENANT_ID']
    ]);
    
    // Register Booking System bridge
    $manager->registerBridge('booking_system', \App\Bridge\BookingSystemBridge::class, [
        'api_base_url' => $_ENV['BOOKING_SYSTEM_API_URL'] ?? 'http://localhost',
        'api_key' => $_ENV['BOOKING_SYSTEM_API_KEY'] ?? null
    ]);
    
    return $manager;
});

$container->set(\App\Controller\BridgeController::class, function () use ($container) {
    return new \App\Controller\BridgeController(
        $container->get('bridgeManager'),
        $container->get('logger')
    );
});

$container->set(\App\Controller\ResourceMappingController::class, function () use ($container) {
    return new \App\Controller\ResourceMappingController(
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
$app->get('/webhook/outlook-notifications', function(Request $request, Response $response, $args) use ($container) {
    // Redirect Outlook webhooks to bridge webhook handler
    $bridgeController = $container->get(\App\Controller\BridgeController::class);
    $request = $request->withAttribute('bridgeName', 'outlook');
    return $bridgeController->handleWebhook($request, $response, ['bridgeName' => 'outlook']);
});

$app->post('/webhook/outlook-notifications', function(Request $request, Response $response, $args) use ($container) {
    // Redirect Outlook webhooks to bridge webhook handler
    $bridgeController = $container->get(\App\Controller\BridgeController::class);
    return $bridgeController->handleWebhook($request, $response, ['bridgeName' => 'outlook']);
});

$app->run();
