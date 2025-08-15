<?php

namespace App\Controller;

use App\Services\BridgeManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * BridgeController provides endpoints to interact with bridges: listing, syncing,
 * webhook handling, subscriptions, resources, diagnostics, and metrics.
 */
class BridgeController
{
    private $bridgeManager;
    private $logger;
    private $db;
    
    /**
     * @param BridgeManager $bridgeManager Bridge orchestrator
     * @param LoggerInterface $logger Logger
     * @param PDO $db Database connection
     */
    public function __construct(BridgeManager $bridgeManager, LoggerInterface $logger, PDO $db)
    {
        $this->bridgeManager = $bridgeManager;
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * List all available bridges.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function listBridges(Request $request, Response $response, $args)
    {
        try {
            $bridges = $this->bridgeManager->getAllBridgesInfo();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'bridges' => $bridges,
                'count' => count($bridges)
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to list bridges', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get calendars for a specific bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function getCalendars(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        
        try {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            $calendars = $bridge->getCalendars();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'bridge_name' => $bridgeName,
                'bridge_type' => $bridge->getBridgeType(),
                'calendars' => $calendars,
                'count' => count($calendars)
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get calendars', [
                'bridge' => $bridgeName,
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Sync between two bridges.
    *
    * @param Request $request Body or query may include start_date, end_date, dry_run, handle_deletions
    * @param Response $response
    * @param array $args Must include sourceBridge and targetBridge
    * @return Response
     */
    public function syncBridges(Request $request, Response $response, $args)
    {
        $sourceBridge = $args['sourceBridge'];
        $targetBridge = $args['targetBridge'];

        // Read both body and query; let query override body
        $queryParams = $request->getQueryParams() ?? [];
        $rawBody = $request->getBody()->getContents();
        $body = json_decode($rawBody ?: '[]', true) ?? [];
        $params = array_merge($body, $queryParams);

        // Helper to coerce booleans from "1", "true", etc.
        $toBool = function ($v, $default = false)
        {
            if ($v === null) return $default;
            if (is_bool($v)) return $v;
            return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        };

        // Support snake_case and camelCase
        $startDate = $params['start_date'] ?? $params['startDate'] ?? date('Y-m-d');
        $endDate   = $params['end_date']   ?? $params['endDate']   ?? date('Y-m-d', strtotime('+30 days'));

        // Determine sync method - defaults to 'manual' but can be overridden
        $syncMethod = $params['sync_method'] ?? $params['syncMethod'] ?? 'manual';
        
        // Auto-detect automated sync methods based on other parameters
        if ($syncMethod === 'manual') {
            if ($toBool($params['handle_deletions'] ?? $params['handleDeletions'] ?? false)) {
                $syncMethod = 'automated'; // Deletion handling usually indicates automated sync
            }
        }

        $options = [
            'handle_deletions' => $toBool($params['handle_deletions'] ?? $params['handleDeletions'] ?? false),
            'skip_updates'     => $toBool($params['skip_updates']     ?? $params['skipUpdates']     ?? false),
            'dry_run'          => $toBool($params['dry_run']          ?? $params['dryRun']          ?? false),
            'sync_method'      => $syncMethod,
        ];

        try {
            $this->logger->info('Bridge sync requested', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'date_range' => [$startDate, $endDate],
                'options' => $options
            ]);

            // Get all active mappings between these bridges (handle bidirectional)
                $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
                $tenantClause = $tenantId !== '' ? " AND (tenant_id = :tenant_id OR tenant_id IS NULL)" : "";
                $sql = "SELECT 
                        source_calendar_id,
                        target_calendar_id,
                        sync_direction, 
                        id,
                        bridge_from,
                        bridge_to
                    FROM bridge_resource_mappings 
                    WHERE (
                        (bridge_from = :bf AND bridge_to = :bt) OR 
                        (bridge_from = :bt AND bridge_to = :bf AND sync_direction IN ('bidirectional', 'target_to_source'))
                    )
                    AND is_active = TRUE AND sync_enabled = TRUE" . $tenantClause;

                $stmt = $this->db->prepare($sql);
                $params = [
                    ':bf' => $sourceBridge,
                    ':bt' => $targetBridge,
                ];
                if ($tenantClause) { $params[':tenant_id'] = $tenantId; }
                $stmt->execute($params);
            $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($mappings)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => "No active mappings found between {$sourceBridge} and {$targetBridge}",
                    'suggestion' => "Create resource mappings first using the /mappings/resources endpoint"
                ]));
                
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $allResults = [];
            $totalSynced = 0;
            $totalErrors = 0;

            foreach ($mappings as $mapping) {
                // Determine the correct source and target calendar IDs based on sync direction
                // The database columns are semantic: source_calendar_id is always the booking system resource
                // and target_calendar_id is always the Outlook calendar
                
                if ($sourceBridge === $mapping['bridge_from'] && $targetBridge === $mapping['bridge_to']) {
                    // Forward direction: booking_system → outlook
                    $sourceCalendarId = $mapping['source_calendar_id']; // booking system resource
                    $targetCalendarId = $mapping['target_calendar_id']; // outlook calendar
                } else {
                    // Reverse direction: outlook → booking_system
                    $sourceCalendarId = $mapping['target_calendar_id']; // outlook calendar (now source)
                    $targetCalendarId = $mapping['source_calendar_id']; // booking system resource (now target)
                }
                
                $syncDirection = $mapping['sync_direction'];

                try {
                    $this->logger->info('Syncing mapping', [
                        'mapping_id' => $mapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'direction' => $syncDirection,
                        'original_bridge_from' => $mapping['bridge_from'],
                        'original_bridge_to' => $mapping['bridge_to']
                    ]);

                    if ($options['dry_run']) {
                        $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
                        $results = $this->performDryRun($tenantId, $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate);
                    } else {
                        $options['tenant_id'] = (string)($request->getAttribute('tenant_id') ?? null);
                        $results = $this->bridgeManager->syncBetweenBridges(
                            $sourceBridge,
                            $targetBridge,
                            $sourceCalendarId,
                            $targetCalendarId,
                            $startDate,
                            $endDate,
                            $options
                        );
                    }

                    $allResults[] = [
                        'mapping_id' => $mapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'results' => $results
                    ];

                    // Calculate total synced events (created + updated)
                    $syncedInThisMapping = ($results['created'] ?? 0) + ($results['updated'] ?? 0);
                    $totalSynced += $syncedInThisMapping;

                } catch (\Exception $e) {
                    $totalErrors++;
                    $allResults[] = [
                        'mapping_id' => $mapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'error' => $e->getMessage()
                    ];
                    
                    $this->logger->error('Mapping sync failed', [
                        'mapping_id' => $mapping['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Calculate totals across all mappings
            $totalCreated = 0;
            $totalUpdated = 0;
            $totalDeleted = 0;
            $totalSkipped = 0;
            $totalSourceEvents = 0;
            
            foreach ($allResults as $mappingResult) {
                if (isset($mappingResult['results']) && !isset($mappingResult['error'])) {
                    $results = $mappingResult['results'];
                    $totalCreated += $results['created'] ?? 0;
                    $totalUpdated += $results['updated'] ?? 0;
                    $totalDeleted += $results['deleted'] ?? 0;
                    $totalSkipped += $results['skipped'] ?? 0;
                    $totalSourceEvents += $results['source_events_found'] ?? 0;
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'mappings_processed' => count($mappings),
                'total_synced' => $totalSynced, 
                'total_errors' => $totalErrors,
                'summary' => [
                    'total_source_events' => $totalSourceEvents,
                    'created' => $totalCreated,
                    'updated' => $totalUpdated,
                    'deleted' => $totalDeleted,
                    'skipped' => $totalSkipped,
                    'errors' => $totalErrors,
                    'success_rate' => $totalSourceEvents > 0 ? round((($totalCreated + $totalUpdated) / $totalSourceEvents) * 100, 2) : 100
                ],
                'sync_results' => $allResults,
                'timestamp' => date('c')
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Bridge sync failed', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Handle webhook from any bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function handleWebhook(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        $body = json_decode($request->getBody()->getContents(), true);
        
        try {
            // Handle Microsoft Graph webhook validation
            $queryParams = $request->getQueryParams();
            if (isset($queryParams['validationToken'])) {
                $response->getBody()->write($queryParams['validationToken']);
                return $response->withHeader('Content-Type', 'text/plain');
            }

            // Validate clientState for Outlook notifications if configured
            if ($bridgeName === 'outlook') {
                $expectedClientState = $_ENV['GRAPH_CLIENT_STATE'] ?? null;
                $clientState = $body['value'][0]['clientState'] ?? null;
                if ($expectedClientState && $clientState && !hash_equals($expectedClientState, $clientState)) {
                    $this->logger->warning('Webhook clientState mismatch', [
                        'expected' => '***',
                        'got' => $clientState
                    ]);
                    $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid clientState']));
                    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
                }
            }
            
            $this->logger->info('Webhook received', [
                'bridge' => $bridgeName,
                'data' => $body
            ]);
            
            // Process Microsoft Graph notifications for deletions
            if ($bridgeName === 'outlook' && isset($body['value'])) {
                $this->processMicrosoftGraphNotifications($body['value']);
            }
            
            // Determine the target bridge for sync
            $targetBridge = $this->determineTargetBridge($bridgeName);
            
            // Queue the sync operation using Redis or database queue
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            $this->queueSyncOperation($bridgeName, $targetBridge, $body, $tenantId);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Webhook processed and sync queued',
                'bridge' => $bridgeName,
                'target_bridge' => $targetBridge
            ]));
            
            return $response->withStatus(202)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'bridge' => $bridgeName,
                'error' => $e->getMessage(),
                'body' => $body
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Process Microsoft Graph webhook notifications for deletions.
     *
     * @param array $notifications Raw Graph notification payloads
     * @return void
     */
    private function processMicrosoftGraphNotifications($notifications)
    {
        foreach ($notifications as $notification) {
            // Microsoft Graph sends notifications for calendar changes
            // We need to check if the event still exists to detect deletions
            if (isset($notification['resource']) && isset($notification['resourceData']['id'])) {
                $resourceUrl = $notification['resource'];
                $eventId = $notification['resourceData']['id'];
                
                // Extract calendar ID from resource URL
                // Format: /users/{userId}/calendar/events/{eventId}
                if (preg_match('/\/users\/([^\/]+)\/calendar\/events/', $resourceUrl, $matches)) {
                    $calendarId = $matches[1];
                    
                    // Queue a deletion check operation
                    $this->queueDeletionCheck($calendarId, $eventId);
                }
            }
        }
    }

    /**
     * Queue a deletion check operation.
     *
     * @param string $calendarId Outlook calendar/user ID
     * @param string $eventId Outlook event ID
     * @return void
     */
    private function queueDeletionCheck($calendarId, $eventId)
    {
        $queueData = [
            'type' => 'deletion_check',
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'timestamp' => date('c')
        ];

    try {
        if (extension_loaded('redis') && class_exists('\\Redis')) {
        $redisClass = '\\Redis';
        $redis = new $redisClass();
                $redis->connect('127.0.0.1', 6379);
                $redis->lpush('bridge_deletion_checks', json_encode($queueData));
                $redis->close();
            } else {
                // Fallback to database queue
        $sql = "INSERT INTO bridge_queue (queue_type, source_bridge, payload, priority, tenant_id) 
            VALUES ('deletion_check', 'outlook', :payload, 1, :tenant_id)";
                $stmt = $this->db->prepare($sql);
        $tenantId = null;
        if (isset($_SERVER['HTTP_X_TENANT_ID'])) { $tenantId = (string)$_SERVER['HTTP_X_TENANT_ID']; }
        $stmt->execute([':payload' => json_encode($queueData), ':tenant_id' => $tenantId]);
            }
            
            $this->logger->info('Deletion check queued', $queueData);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to queue deletion check', [
                'error' => $e->getMessage(),
                'data' => $queueData
            ]);
        }
    }
    
    /**
    * Create webhook subscriptions for a bridge.
    *
    * @param Request $request JSON body may include webhook_url and calendar_ids[]
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function createSubscriptions(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        $body = json_decode($request->getBody()->getContents(), true);
        
        try {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            $webhookUrl = $body['webhook_url'] ?? $this->getDefaultWebhookUrl($bridgeName);
            $calendarIds = $body['calendar_ids'] ?? [];
            
            if (empty($calendarIds)) {
                // Subscribe to all calendars
                $calendars = $bridge->getCalendars();
                $calendarIds = array_column($calendars, 'id');
            }
            
            $subscriptions = [];
            $errors = [];
            
            foreach ($calendarIds as $calendarId) {
                try {
                    $subscriptionId = $bridge->subscribeToChanges($calendarId, $webhookUrl);
                    $subscriptions[] = [
                        'calendar_id' => $calendarId,
                        'subscription_id' => $subscriptionId,
                        'webhook_url' => $webhookUrl
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'calendar_id' => $calendarId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'bridge' => $bridgeName,
                'subscriptions' => $subscriptions,
                'errors' => $errors,
                'total_subscriptions' => count($subscriptions),
                'total_errors' => count($errors)
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create subscriptions', [
                'bridge' => $bridgeName,
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get health status of all bridges.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function getHealthStatus(Request $request, Response $response, $args)
    {
        try {
            // Prefer tenant-scoped health if tenant is resolved
            $tenantId = $request->getAttribute('tenant_id');
            if (!empty($tenantId)) {
                $bridgesInfo = $this->bridgeManager->getAllBridgesInfoForTenant((string)$tenantId);
            } else {
                $bridgesInfo = $this->bridgeManager->getAllBridgesInfo();
            }
            $overallHealth = 'healthy';
            $healthyCount = 0;
            $unhealthyCount = 0;
            
            foreach ($bridgesInfo as $bridgeInfo) {
                if (isset($bridgeInfo['health']['status'])) {
                    if ($bridgeInfo['health']['status'] === 'healthy') {
                        $healthyCount++;
                    } else {
                        $unhealthyCount++;
                        $overallHealth = 'degraded';
                    }
                }
            }
            
            if ($unhealthyCount === count($bridgesInfo)) {
                $overallHealth = 'unhealthy';
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'overall_health' => $overallHealth,
                'bridges' => $bridgesInfo,
                'summary' => [
                    'total_bridges' => count($bridgesInfo),
                    'healthy_bridges' => $healthyCount,
                    'unhealthy_bridges' => $unhealthyCount
                ],
                'timestamp' => date('c')
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'overall_health' => 'unhealthy'
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Perform dry run sync to see what would happen.
    *
    * @param string $tenantId Tenant identifier
    * @param string $sourceBridge Source bridge name
    * @param string $targetBridge Target bridge name
    * @param string $sourceCalendarId Source calendar/resource ID
    * @param string $targetCalendarId Target calendar/resource ID
    * @param string $startDate ISO date
    * @param string $endDate ISO date
    * @return array
     */
    private function performDryRun(string $tenantId, $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate)
    {
        $source = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridge);
        $sourceEvents = $source->getEvents($sourceCalendarId, $startDate, $endDate);
        
        return [
            'dry_run' => true,
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'source_events_found' => count($sourceEvents),
            'events_to_process' => $sourceEvents,
            'note' => 'This is a dry run - no actual changes were made'
        ];
    }
    
    /**
    * Determine target bridge for webhook.
    *
    * @param string $sourceBridge
    * @return string|null Target bridge name or null if unknown
    */
    private function determineTargetBridge($sourceBridge)
    {
        // Simple mapping - can be made configurable
        $mappings = [
            'outlook' => 'booking_system',
            'booking_system' => 'outlook'
        ];
        
        return $mappings[$sourceBridge] ?? null;
    }
    
    /**
    * Queue sync operation for async processing.
    *
    * @param string $sourceBridge
    * @param string|null $targetBridge
    * @param array $webhookData Payload
    * @param string|null $tenantId Tenant identifier
    * @return void
    */
    private function queueSyncOperation($sourceBridge, $targetBridge, $webhookData, ?string $tenantId = null)
    {
        // Add to Redis queue if available, otherwise use database queue
        try {
            if (extension_loaded('redis') && class_exists('\\Redis')) {
                $redisClass = '\\Redis';
                $redis = new $redisClass();
                $redis->connect('localhost', 6379);
                
                $queueData = [
                    'type' => 'bridge_sync',
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'webhook_data' => $webhookData,
                    'created_at' => time(),
                    'priority' => 1 // High priority for webhook-triggered syncs
                ];
                
                $redis->zadd('bridge_sync_queue', time(), json_encode($queueData));
            } else {
                // Fallback to database queue
                $this->queueToDatabase($sourceBridge, $targetBridge, $webhookData, $tenantId);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('Redis not available, using database queue fallback', [
                'error' => $e->getMessage()
            ]);
            $this->queueToDatabase($sourceBridge, $targetBridge, $webhookData, $tenantId);
        }
    }
    
    /**
    * Fallback queue to database.
    *
    * @param string $sourceBridge
    * @param string|null $targetBridge
    * @param array $webhookData
    * @param string|null $tenantId Tenant identifier
    * @return void
    */
    private function queueToDatabase($sourceBridge, $targetBridge, $webhookData, ?string $tenantId = null)
    {
        try {
            $sql = "INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, payload, priority, tenant_id) 
                    VALUES ('bridge_sync', :source_bridge, :target_bridge, :payload, 1, :tenant_id)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':source_bridge' => $sourceBridge,
                ':target_bridge' => $targetBridge,
                ':payload' => json_encode($webhookData),
                ':tenant_id' => $tenantId
            ]);
            $this->logger->info('Webhook queued to DB', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge
            ]);
        } catch (\Exception $e) {
            $this->logger->error('DB queue insert failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
    * Get default webhook URL for a bridge.
    *
    * @param string $bridgeName
    * @return string
    */
    private function getDefaultWebhookUrl($bridgeName)
    {
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
    return "{$baseUrl}/bridges/webhook/{$bridgeName}";
    }
    
    /**
    * Trigger manual deletion sync check.
    * POST /bridges/sync-deletions
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function syncDeletions(Request $request, Response $response, $args)
    {
        try {
            $deletionService = new \App\Services\DeletionSyncService(
                $this->db, 
                $this->logger, 
                $this->bridgeManager
            );
            
            $results = $deletionService->syncDeletedEvents();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Deletion sync completed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Deletion sync failed', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
    * Process deletion check queue.
    * POST /bridges/process-deletion-queue
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function processDeletionQueue(Request $request, Response $response, $args)
    {
        try {
            $deletionService = new \App\Services\DeletionSyncService(
                $this->db, 
                $this->logger, 
                $this->bridgeManager
            );
            
            $results = $deletionService->processDeletionChecks();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Deletion queue processed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Deletion queue processing failed', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get available resources for a specific bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function getAvailableResources(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);

            $queryParams = $request->getQueryParams();
            $nameFilter = $queryParams['query'] ?? null;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 0;
            $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
            
            // Validate pagination parameters
            if ($limit < 0) $limit = 0;
            if ($offset < 0) $offset = 0;
            
            // Get available resources through the bridge
            $result = $bridge->getAvailableResources($nameFilter, $limit, $offset);
            
            // Handle both old array format and new format with metadata
            if (isset($result['resources']) && isset($result['metadata'])) {
                $resources = $result['resources'];
                $metadata = $result['metadata'];
            } else {
                // Backward compatibility: assume it's just an array of resources
                $resources = $result;
                $metadata = [];
            }
            
            $responseData = [
                'success' => true,
                'bridge' => $bridgeName,
                'resources' => $resources,
                'count' => count($resources)
            ];
            
            // Add total_records from API response if available
            if (isset($metadata['total_records'])) {
                $responseData['total_records'] = $metadata['total_records'];
            }
            
            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0) {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($resources)
                ];
                
                // Add total_records to pagination if available
                if (isset($metadata['total_records'])) {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }
            
            $response->getBody()->write(json_encode($responseData));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available resources', [
                'bridge' => $args['bridgeName'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'bridge' => $args['bridgeName'] ?? 'unknown'
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get available groups/collections for a specific bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function getAvailableGroups(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            
            $queryParams = $request->getQueryParams();
            $nameFilter = $queryParams['query'] ?? null;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 0;
            $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
            
            // Validate pagination parameters
            if ($limit < 0) $limit = 0;
            if ($offset < 0) $offset = 0;
            
            // Get available groups through the bridge
            $result = $bridge->getAvailableGroups($nameFilter, $limit, $offset);
            
            // Handle both old array format and new format with metadata
            if (isset($result['resources']) && isset($result['metadata'])) {
                $groups = $result['resources'];
                $metadata = $result['metadata'];
            } else {
                // Backward compatibility: assume it's just an array of groups
                $groups = $result;
                $metadata = [];
            }
            
            $responseData = [
                'success' => true,
                'bridge' => $bridgeName,
                'groups' => $groups,
                'count' => count($groups)
            ];
            
            // Add total_records from API response if available
            if (isset($metadata['total_records'])) {
                $responseData['total_records'] = $metadata['total_records'];
            }
            
            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0) {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($groups)
                ];
                
                // Add total_records to pagination if available
                if (isset($metadata['total_records'])) {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }
            
            $response->getBody()->write(json_encode($responseData));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available groups', [
                'bridge' => $args['bridgeName'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'bridge' => $args['bridgeName'] ?? 'unknown'
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get calendar items for a specific resource on a bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName and resourceId
    * @return Response
     */
    public function getResourceCalendarItems(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'];
            $resourceId = $args['resourceId'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            
            // Get query parameters
            $queryParams = $request->getQueryParams();
            $startDate = $queryParams['startDate'] ?? date('Y-m-d');
            $endDate = $queryParams['endDate'] ?? null;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 0;
            $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
            
            // Validate pagination parameters
            if ($limit < 0) $limit = 0;
            if ($offset < 0) $offset = 0;
            
            // Get calendar items through the bridge
            $result = $bridge->getResourceCalendarItems($resourceId, $startDate, $endDate, $limit, $offset);
            
            // Handle both old array format and new format with metadata
            if (isset($result['calendar_items']) && isset($result['metadata'])) {
                $calendarItems = $result['calendar_items'];
                $metadata = $result['metadata'];
            } else {
                // Backward compatibility: assume it's just an array of calendar items
                $calendarItems = is_array($result) ? $result : [];
                $metadata = [];
            }
            
            $responseData = [
                'success' => true,
                'bridge' => $bridgeName,
                'resource_id' => $resourceId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'calendar_items' => $calendarItems,
                'count' => count($calendarItems)
            ];
            
            // Add total_records from API response if available
            if (isset($metadata['total_records'])) {
                $responseData['total_records'] = $metadata['total_records'];
            }
            
            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0) {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($calendarItems)
                ];
                
                // Add total_records to pagination if available
                if (isset($metadata['total_records'])) {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }
            
            $response->getBody()->write(json_encode($responseData));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get resource calendar items', [
                'bridge' => $args['bridgeName'] ?? 'unknown',
                'resource_id' => $args['resourceId'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'bridge' => $args['bridgeName'] ?? 'unknown',
                'user_id' => $args['userId'] ?? 'unknown'
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
    * Get session diagnostics for debugging.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function getSessionDiagnostics(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'];
            
            // Get bridge instance
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            
            // Get session diagnostics if the bridge supports it
            $diagnostics = [];
            if (method_exists($bridge, 'getSessionDiagnostics')) {
                $diagnostics = $bridge->getSessionDiagnostics();
            } else {
                $diagnostics = [
                    'error' => 'Bridge does not support session diagnostics',
                    'bridge_type' => $bridgeName,
                    'available_methods' => get_class_methods($bridge)
                ];
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'bridge' => $bridgeName,
                'session_diagnostics' => $diagnostics
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get session diagnostics', [
                'bridge' => $args['bridgeName'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'bridge' => $args['bridgeName'] ?? 'unknown'
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
    * Process pending syncs for a specific bridge or all bridges.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function processPendingSyncs(Request $request, Response $response, $args)
    {
        try {
            $body = json_decode($request->getBody()->getContents(), true) ?? [];
            $bridgeName = $args['bridgeName'] ?? null;
            $batchSize = $body['batch_size'] ?? 50;
            
            // Process pending syncs
            $results = $this->bridgeManager->processPendingSyncs($bridgeName, $batchSize);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Pending syncs processed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process pending syncs', [
                'bridge' => $bridgeName ?? 'all',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Re-enable failed events for a bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args May include bridgeName
    * @return Response
     */
    public function reEnableFailedEvents(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'] ?? null;
            $body = json_decode($request->getBody()->getContents(), true) ?? [];
            $eventIds = $body['event_ids'] ?? [];
            
            // Re-enable failed events
            $results = $this->bridgeManager->reEnableFailedEvents($bridgeName, $eventIds);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Failed events re-enabled',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to re-enable failed events', [
                'bridge' => $bridgeName ?? 'all',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get sync statistics for all bridges.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args May include bridgeName
    * @return Response
     */
    public function getSyncStats(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'] ?? null;
            
            if ($bridgeName) {
                // Get stats for specific bridge
                $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
                $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
                $stats = $bridge->getSyncStats();
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'bridge_name' => $bridgeName,
                    'stats' => $stats
                ]));
            } else {
                // Get stats for all bridges
                $allStats = $this->bridgeManager->getAllSyncStats();
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'all_bridge_stats' => $allStats
                ]));
            }
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sync stats', [
                'bridge' => $bridgeName ?? 'all',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get cancelled events for cleanup.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args May include bridgeName
    * @return Response
     */
    public function getCancelledEvents(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'] ?? null;
            
            if ($bridgeName) {
                // Get cancelled events for specific bridge
                $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
                $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
                $cancelledEvents = $bridge->getCancelledEvents($bridgeName, 100);
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'bridge_name' => $bridgeName,
                    'cancelled_events' => $cancelledEvents,
                    'count' => count($cancelledEvents)
                ]));
            } else {
                // Get cancelled events for all bridges
                $allCancelledEvents = $this->bridgeManager->getAllCancelledEvents();
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'all_cancelled_events' => $allCancelledEvents
                ]));
            }
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get cancelled events', [
                'bridge' => $bridgeName ?? 'all',
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
    * Get events pending sync for a bridge.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include bridgeName
    * @return Response
     */
    public function getPendingSyncEvents(Request $request, Response $response, $args)
    {
        try {
            $bridgeName = $args['bridgeName'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            
            $pendingEvents = $bridge->getEventsToSync($bridgeName, 3);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'bridge_name' => $bridgeName,
                'pending_events' => $pendingEvents,
                'count' => count($pendingEvents)
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get pending sync events', [
                'bridge' => $bridgeName,
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
