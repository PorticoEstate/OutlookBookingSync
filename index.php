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

// Get resource to Outlook calendar mapping information
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

$app->run();
