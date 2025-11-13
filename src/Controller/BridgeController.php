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
        try
        {
            $bridges = $this->bridgeManager->getAllBridgesInfo();

            $response->getBody()->write(json_encode([
                'success' => true,
                'bridges' => $bridges,
                'count' => count($bridges)
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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

        try
        {
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
        }
        catch (\Exception $e)
        {
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
        if ($syncMethod === 'manual')
        {
            if ($toBool($params['handle_deletions'] ?? $params['handleDeletions'] ?? true))
            {
                $syncMethod = 'automated'; // Deletion handling usually indicates automated sync
            }
        }

        $options = [
            'handle_deletions' => $toBool($params['handle_deletions'] ?? $params['handleDeletions'] ?? true),
            'skip_updates'     => $toBool($params['skip_updates']     ?? $params['skipUpdates']     ?? false),
            'dry_run'          => $toBool($params['dry_run']          ?? $params['dryRun']          ?? false),
            'sync_method'      => $syncMethod,
            // Optional policy: if true, do not recreate target when user deletes it (for one-way mappings)
            // NOTE: Defaulting to true to avoid unintended recreations
            'respect_target_deletions' => $toBool($params['respect_target_deletions'] ?? $params['respectTargetDeletions'] ?? true)
        ];

        try
        {
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
                        tenant_id,
                        source_calendar_id,
                        target_calendar_id,
                        sync_direction, 
                        id,
                        bridge_from,
                        bridge_to
                    FROM bridge_resource_mappings 
                    WHERE (
                        (bridge_from = :bf AND bridge_to = :bt) OR 
                        (bridge_from = :bt AND bridge_to = :bf)
                    )
                    AND is_active = TRUE AND sync_enabled = TRUE" . $tenantClause;

            $stmt = $this->db->prepare($sql);
            $params = [
                ':bf' => $sourceBridge,
                ':bt' => $targetBridge,
            ];
            if ($tenantClause)
            {
                $params[':tenant_id'] = $tenantId;
            }
            $stmt->execute($params);
            $resourceMappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($resourceMappings))
            {
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

            foreach ($resourceMappings as $resourceMapping)
            {
                // Use tenant_id from the resource mapping record for proper isolation
                $mappingTenantId = $resourceMapping['tenant_id'];

                // Determine the correct source and target calendar IDs based on sync direction
                // The database columns are semantic: source_calendar_id is always the booking system resource
                // and target_calendar_id is always the Outlook calendar

                if ($sourceBridge === $resourceMapping['bridge_from'] && $targetBridge === $resourceMapping['bridge_to'])
                {
                    // Forward direction: booking_system → outlook
                    $sourceCalendarId = $resourceMapping['source_calendar_id']; // booking system resource
                    $targetCalendarId = $resourceMapping['target_calendar_id']; // outlook calendar
                }
                else
                {
                    // Reverse direction: outlook → booking_system
                    $sourceCalendarId = $resourceMapping['target_calendar_id']; // outlook calendar (now source)
                    $targetCalendarId = $resourceMapping['source_calendar_id']; // booking system resource (now target)
                }


                try
                {
                    $this->logger->info('Syncing mapping', [
                        'mapping_id' => $resourceMapping['id'],
                        'mapping_tenant_id' => $mappingTenantId,
                        'request_tenant_id' => $tenantId,
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'original_bridge_from' => $resourceMapping['bridge_from'],
                        'original_bridge_to' => $resourceMapping['bridge_to']
                    ]);

                    if ($options['dry_run'])
                    {
                        $results = $this->performDryRun($mappingTenantId ?: 'default', $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate);
                    }
                    else
                    {
                        // Use tenant_id from the resource mapping record
                        $options['tenant_id'] = $mappingTenantId;

                        // Pass mapping configuration for ownership decisions
                        $options['mapping_config'] = [
                            'mapping_id' => $resourceMapping['id'],
                            'bridge_from' => $sourceBridge, //actual source bridge for this sync call
                            'bridge_to' => $targetBridge, //actual target bridge for this sync call
                        ];
                        
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
                        'mapping_id' => $resourceMapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'results' => $results
                    ];

                    // On successful HTTP sync (not dry-run), bump resource-level last_synced_at
                    if (!$options['dry_run'])
                    {
                        try
                        {
                            $failedEvents = $results['summary']['failed_events'] ?? 0;
                            $hasSummary = isset($results['summary']);
                            // Treat as success when there is no summary (legacy) or when failed_events == 0
                            if (!$hasSummary || $failedEvents === 0)
                            {
                                $stmtUpdate = $this->db->prepare("UPDATE bridge_resource_mappings SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id");
                                $stmtUpdate->execute([':id' => (int)$resourceMapping['id']]);
                            }
                        }
                        catch (\Throwable $e)
                        {
                            // Log but do not fail the request if timestamp update fails
                            $this->logger->warning('Failed to update resource last_synced_at after HTTP sync', [
                                'mapping_id' => $resourceMapping['id'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Calculate total synced events (created + updated)
                    $syncedInThisMapping = ($results['created'] ?? 0) + ($results['updated'] ?? 0);
                    $totalSynced += $syncedInThisMapping;
                }
                catch (\Exception $e)
                {
                    $totalErrors++;
                    $allResults[] = [
                        'mapping_id' => $resourceMapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'error' => $e->getMessage()
                    ];

                    $this->logger->error('Mapping sync failed', [
                        'mapping_id' => $resourceMapping['id'],
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

            foreach ($allResults as $mappingResult)
            {
                if (isset($mappingResult['results']) && !isset($mappingResult['error']))
                {
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
                'mappings_processed' => count($resourceMappings),
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
        }
        catch (\Exception $e)
        {
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

        try
        {
            // Handle Microsoft Graph webhook validation
            $queryParams = $request->getQueryParams();
            if (isset($queryParams['validationToken']))
            {
                $response->getBody()->write($queryParams['validationToken']);
                return $response->withHeader('Content-Type', 'text/plain');
            }

            // Handle booking system webhook validation handshake
            if ($bridgeName === 'booking_system' && isset($queryParams['challenge']))
            {
                $challenge = $queryParams['challenge'];
                $this->logger->info('Booking system webhook validation handshake', [
                    'challenge' => $challenge
                ]);
                $response->getBody()->write($challenge);
                return $response->withHeader('Content-Type', 'text/plain');
            }

            // Validate clientState for Outlook notifications if configured
            if ($bridgeName === 'outlook')
            {
                $expectedClientState = $_ENV['GRAPH_CLIENT_STATE'] ?? null;
                $clientState = $body['value'][0]['clientState'] ?? null;
                if ($expectedClientState && $clientState && !hash_equals($expectedClientState, $clientState))
                {
                    $this->logger->warning('Webhook clientState mismatch', [
                        'expected' => '***',
                        'got' => $clientState
                    ]);
                    $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid clientState']));
                    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
                }
            }

            // Get tenant ID early for validation - prefer query parameter from webhook URL, fallback to middleware
            $queryParams = $request->getQueryParams();
            $tenantId = (string)($queryParams['tenant_id'] ?? $request->getAttribute('tenant_id') ?? '');

            // Validate clientState for booking system notifications if configured
            if ($bridgeName === 'booking_system')
            {
                $clientState = $body['client_state'] ?? null;
                if ($clientState)
                {
                    try
                    {
                        // Get bridge instance to access webhook_client_secret from config
                        /** @var \App\Bridge\BookingSystemBridge $bookingBridge */
                        $bookingBridge = $this->bridgeManager->getBridgeForTenant($tenantId, 'booking_system');
                        
                        // Generate expected client state using same logic as subscription creation
                        $clientSecret = $bookingBridge->getConfig()['webhook_client_secret'] ?? $_ENV['WEBHOOK_CLIENT_SECRET'] ?? null;
                        if ($clientSecret)
                        {
                            $payload = sprintf('booking-bridge-%s-%s', $tenantId, $clientSecret);
                            $expectedClientState = hash('sha256', $payload);
                            
                            if (!hash_equals($expectedClientState, $clientState))
                            {
                                $this->logger->warning('Booking system webhook clientState mismatch', [
                                    'tenant_id' => $tenantId,
                                    'expected_hash' => substr($expectedClientState, 0, 10) . '...',
                                    'got_hash' => substr($clientState, 0, 10) . '...'
                                ]);
                                $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid clientState']));
                                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
                            }
                        }
                        // If no client secret configured, skip validation (allow any client state)
                    }
                    catch (\Exception $e)
                    {
                        $this->logger->error('Failed to validate booking system client state', [
                            'error' => $e->getMessage(),
                            'tenant_id' => $tenantId
                        ]);
                        // Don't block webhook on validation errors - log and continue
                    }
                }
            }

            $this->logger->info('Webhook received', [
                'bridge' => $bridgeName,
                'data' => $body
            ]);

            // Determine the target bridge for sync
            $targetBridge = $this->determineTargetBridge($bridgeName);

            // Process Microsoft Graph notifications and transform them
            if ($bridgeName === 'outlook' && isset($body['value']))
            {
                // Process deletion checks first
                $this->processMicrosoftGraphNotifications($body['value']);
                
                // Transform Graph notifications to internal format and queue sync operations
                foreach ($body['value'] as $notification)
                {
                    $transformedPayload = $this->transformOutlookNotification($notification, $tenantId);
                    if ($transformedPayload)
                    {
                        $this->queueSyncOperation($bridgeName, $targetBridge, $transformedPayload, $tenantId);
                    }
                }
            }
            elseif ($bridgeName === 'booking_system')
            {
                // Process booking system notifications
                // Booking system can send single notification or batch
                $notifications = [];
                
                if (isset($body['value']) && is_array($body['value']))
                {
                    // Batch format with 'value' array
                    $notifications = $body['value'];
                }
                elseif (isset($body['entity_type']) || isset($body['entityId']))
                {
                    // Single notification format
                    $notifications = [$body];
                }
                else
                {
                    $this->logger->warning('Unknown booking system notification format', [
                        'body' => $body,
                        'tenant_id' => $tenantId
                    ]);
                }
                
                // Transform and queue each notification
                foreach ($notifications as $notification)
                {
                    $transformedPayload = $this->transformBookingSystemNotification($notification, $tenantId);
                    if ($transformedPayload)
                    {
                        $this->queueSyncOperation($bridgeName, $targetBridge, $transformedPayload, $tenantId);
                    }
                }
            }
            else
            {
                // For other bridges, queue the raw payload
                $this->queueSyncOperation($bridgeName, $targetBridge, $body, $tenantId);
            }

            // Prepare response data
            $responseData = [
                'success' => true,
                'message' => 'Webhook processed and sync queued',
                'bridge' => $bridgeName,
                'target_bridge' => $targetBridge
            ];

            $response->getBody()->write(json_encode($responseData));
            $response = $response->withStatus(202)->withHeader('Content-Type', 'application/json');

            // Check if we should process the queue immediately after responding
            $immediateProcessing = $_ENV['WEBHOOK_IMMEDIATE_PROCESSING'] ?? 'true';
            if ($immediateProcessing === 'true')
            {
                // Use FastCGI finish request to send response before processing
                if (function_exists('fastcgi_finish_request'))
                {
                    // For FastCGI: finish the request and then process
                    fastcgi_finish_request();
                    
                    // Now process the queue after response is sent
                    $this->processWebhookQueueImmediate($tenantId);
                }
                else
                {
                    // Fallback: queue processing will be handled by cron job HTTP endpoint
                    $this->logger->info('Webhook queued for background processing', [
                        'tenant_id' => $tenantId,
                        'note' => 'Processing will be handled by cron job (/bridges/process-webhook-queue) - fastcgi_finish_request not available'
                    ]);
                }
            }

            return $response;
        }
        catch (\Exception $e)
        {
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
        foreach ($notifications as $notification)
        {
            // Microsoft Graph sends notifications for calendar changes
            // We need to check if the event still exists to detect deletions
            if (isset($notification['resource']) && isset($notification['resourceData']['id']))
            {
                $resourceUrl = $notification['resource'];
                $eventId = $notification['resourceData']['id'];

                // Extract calendar ID from resource URL
                // Format: /users/{userId}/calendar/events/{eventId}
                if (preg_match('/\/users\/([^\/]+)\/calendar\/events/', $resourceUrl, $matches))
                {
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

        try
        {
            if (extension_loaded('redis') && class_exists('\\Redis'))
            {
                $redisClass = '\\Redis';
                $redis = new $redisClass();
                $redis->connect('127.0.0.1', 6379);
                $redis->lpush('bridge_deletion_checks', json_encode($queueData));
                $redis->close();
            }
            else
            {
                // Fallback to database queue
                $sql = "INSERT INTO bridge_queue (queue_type, source_bridge, payload, priority, tenant_id) 
            VALUES ('deletion_check', 'outlook', :payload, 1, :tenant_id)";
                $stmt = $this->db->prepare($sql);
                $tenantId = null;
                if (isset($_SERVER['HTTP_X_TENANT_ID']))
                {
                    $tenantId = (string)$_SERVER['HTTP_X_TENANT_ID'];
                }
                $stmt->execute([':payload' => json_encode($queueData), ':tenant_id' => $tenantId]);
            }

            $this->logger->info('Deletion check queued', $queueData);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to queue deletion check', [
                'error' => $e->getMessage(),
                'data' => $queueData
            ]);
        }
    }

    /**
     * Transform Microsoft Graph notification to internal webhook format.
     *
     * @param array $notification Raw Microsoft Graph notification
     * @param string|null $tenantId Tenant identifier
     * @return array|null Transformed payload or null if invalid
     */
    private function transformOutlookNotification($notification, $tenantId)
    {
        try {
            // Extract basic notification data
            $changeType = $notification['changeType'] ?? null;
            $resourceUrl = $notification['resource'] ?? null;
            $eventId = $notification['resourceData']['id'] ?? null;

            if (!$resourceUrl || !$eventId) {
                $this->logger->warning('Invalid Outlook notification - missing resource or event ID', [
                    'notification' => $notification,
                    'tenant_id' => $tenantId
                ]);
                return null;
            }

            // Extract calendar ID from resource URL
            // Format: Users/{userGuid}/Events/{eventId}
            if (preg_match('/users\/([^\/]+)\/events/i', $resourceUrl, $matches))
            {
                $userGuid = $matches[1];
                
                // Resolve GUID to email address
                $calendarId = $this->resolveUserGuidToEmail($userGuid, $tenantId);
                
                if (!$calendarId) {
                    $this->logger->warning('Could not resolve user GUID to email address', [
                        'user_guid' => $userGuid,
                        'resource_url' => $resourceUrl,
                        'tenant_id' => $tenantId
                    ]);
                    return null;
                }
                
                // Transform to internal format
                $transformedPayload = [
                    'resource_id' => $calendarId, // Now contains the email address
                    'event_id' => $eventId,       // The Outlook event ID
                    'change_type' => $changeType, // created, updated, deleted
                    'timestamp' => date('c'),     // Current timestamp
                    'source' => 'outlook_webhook',
                    'original_notification' => $notification
                ];

                $this->logger->info('Transformed Outlook notification', [
                    'original_resource' => $resourceUrl,
                    'user_guid' => $userGuid,
                    'resolved_email' => $calendarId,
                    'event_id' => $eventId,
                    'change_type' => $changeType,
                    'tenant_id' => $tenantId
                ]);

                return $transformedPayload;
            } else {
                $this->logger->warning('Could not parse calendar ID from resource URL', [
                    'resource_url' => $resourceUrl,
                    'notification' => $notification,
                    'tenant_id' => $tenantId
                ]);
                return null;
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to transform Outlook notification', [
                'error' => $e->getMessage(),
                'notification' => $notification,
                'tenant_id' => $tenantId
            ]);
            return null;
        }
    }

    /**
     * Resolve user GUID to email address using Microsoft Graph API.
     *
     * @param string $userGuid User GUID from resource URL
     * @param string|null $tenantId Tenant identifier
     * @return string|null Email address or null if resolution fails
     */
    private function resolveUserGuidToEmail(string $userGuid, ?string $tenantId = null): ?string
    {
        try 
        {
            // Get Outlook bridge instance to access Graph API
            $outlookBridge = $this->bridgeManager->getBridgeForTenant($tenantId ?: 'default', 'outlook');
            
            // Use the bridge's Graph API client to resolve the GUID
            return $outlookBridge->resolveUserGuidToEmail($userGuid);
        } 
        catch (\Exception $e) 
        {
            $this->logger->error('Failed to resolve user GUID to email', [
                'user_guid' => $userGuid,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Transform booking system webhook notification to internal format.
     *
     * Expected notification format:
     * {
     *   "subscription_id": "sub_12345",
     *   "change_type": "created|updated|deleted",
     *   "entity_type": "event|booking|allocation",
     *   "resource_id": 976,
     *   "entityId": 117905,
     *   "entity_data": { ... entity details ... }
     * }
     *
     * @param array $notification Raw booking system notification
     * @param string|null $tenantId Tenant identifier
     * @return array|null Transformed payload or null if invalid
     */
    private function transformBookingSystemNotification($notification, $tenantId)
    {
        try
        {
            // Extract notification data
            $changeType = $notification['change_type'] ?? null;
            $entityType = $notification['entity_type'] ?? null;
            $entityId = $notification['entityId'] ?? null;
            $resourceId = $notification['resource_id'] ?? null;
            $timestamp = $notification['timestamp'] ?? date('c');

            if (!$entityId)
            {
                $this->logger->warning('Invalid booking system notification - missing entityId', [
                    'notification' => $notification,
                    'tenant_id' => $tenantId
                ]);
                return null;
            }

            if (!$changeType)
            {
                $this->logger->warning('Invalid booking system notification - missing change_type', [
                    'notification' => $notification,
                    'tenant_id' => $tenantId
                ]);
                return null;
            }

            // Transform to internal format matching Outlook notification structure
            // Create composite event ID with entity_type prefix (e.g., "event_117905")
            $compositeEventId = ($entityType ? $entityType . '_' : '') . $entityId;
            
            $transformedPayload = [
                'resource_id' => $resourceId,
                'event_id' => $compositeEventId,
                'entity_type' => $entityType,
                'change_type' => $changeType,
                'timestamp' => $timestamp,
                'source' => 'booking_system_webhook',
                'original_notification' => $notification
            ];

            $this->logger->info('Transformed booking system notification', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'resource_id' => $resourceId,
                'change_type' => $changeType,
                'change_type' => $changeType,
                'tenant_id' => $tenantId
            ]);

            return $transformedPayload;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to transform booking system notification', [
                'error' => $e->getMessage(),
                'notification' => $notification,
                'tenant_id' => $tenantId
            ]);
            return null;
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

        try
        {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
            $webhookUrl = $body['webhook_url'] ?? $this->getDefaultWebhookUrl($bridgeName);
            $calendarIds = $body['calendar_ids'] ?? [];

            if (empty($calendarIds))
            {
                // Subscribe to all calendars
                $calendars = $bridge->getCalendars();
                $calendarIds = array_column($calendars, 'id');
            }

            $subscriptions = [];
            $errors = [];

            foreach ($calendarIds as $calendarId)
            {
                try
                {
                    $subscriptionId = $bridge->subscribeToChanges($calendarId, $webhookUrl);
                    $subscriptions[] = [
                        'calendar_id' => $calendarId,
                        'subscription_id' => $subscriptionId,
                        'webhook_url' => $webhookUrl
                    ];
                }
                catch (\Exception $e)
                {
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
        }
        catch (\Exception $e)
        {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * List webhook subscriptions for a bridge.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Must include bridgeName
     * @return Response
     */
    public function listSubscriptions(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        $queryParams = $request->getQueryParams();

        try
        {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // Build SQL query
            $sql = "SELECT id, subscription_id, calendar_id, bridge_type, webhook_url, 
                          is_active, expires_at, last_renewed_at, created_at 
                   FROM bridge_subscriptions 
                   WHERE bridge_type = :bridge_type";
            $params = [':bridge_type' => $bridgeName];

            // Add tenant filter if specified
            if ($tenantId !== '')
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                $params[':tenant_id'] = $tenantId;
            }

            // Add search filter
            if (!empty($queryParams['search']))
            {
                $sql .= " AND calendar_id ILIKE :search";
                $params[':search'] = '%' . $queryParams['search'] . '%';
            }

            // Add status filter
            if (!empty($queryParams['status']))
            {
                $status = $queryParams['status'];
                if ($status === 'active')
                {
                    $sql .= " AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())";
                }
                elseif ($status === 'expired')
                {
                    $sql .= " AND expires_at IS NOT NULL AND expires_at <= NOW()";
                }
                elseif ($status === 'expiring')
                {
                    $sql .= " AND expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= (NOW() + interval '24 hours')";
                }
            }

            // Handle stats_only request
            if (!empty($queryParams['stats_only']))
            {
                $statsSql = "SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = TRUE THEN 1 END) as active,
                    COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 END) as expired,
                    COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= (NOW() + interval '24 hours') THEN 1 END) as expiring_24h
                FROM bridge_subscriptions 
                WHERE bridge_type = :bridge_type";
                
                if ($tenantId !== '')
                {
                    $statsSql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                }

                $stmt = $this->db->prepare($statsSql);
                $stmt->execute($params);
                $stats = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'stats' => $stats
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Add pagination
            $limit = max(1, min(200, (int)($queryParams['limit'] ?? 50)));
            $offset = max(0, (int)($queryParams['offset'] ?? 0));
            
            $countSql = str_replace('SELECT id, subscription_id, calendar_id, bridge_type, webhook_url, is_active, expires_at, last_renewed_at, created_at', 'SELECT COUNT(*)', $sql);
            $stmt = $this->db->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $response->getBody()->write(json_encode([
                'success' => true,
                'subscriptions' => $subscriptions,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'page' => floor($offset / $limit),
                    'pages' => ceil($total / $limit)
                ]
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to list subscriptions', [
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
     * Delete a webhook subscription.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Must include bridgeName and subscriptionId
     * @return Response
     */
    public function deleteSubscription(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        $subscriptionId = $args['subscriptionId'];

        try
        {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // First check if subscription exists and get calendar_id
            $sql = "SELECT calendar_id FROM bridge_subscriptions 
                   WHERE subscription_id = :subscription_id AND bridge_type = :bridge_type";
            $params = [
                ':subscription_id' => $subscriptionId,
                ':bridge_type' => $bridgeName
            ];

            if ($tenantId !== '')
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                $params[':tenant_id'] = $tenantId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $subscription = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$subscription)
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Subscription not found'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // Try to unsubscribe from the provider (if bridge supports it)
            try
            {
                $bridge = $this->bridgeManager->getBridgeForTenant($tenantId ?: 'default', $bridgeName);
                if (method_exists($bridge, 'unsubscribeFromChanges'))
                {
                    $bridge->unsubscribeFromChanges($subscriptionId);
                }
            }
            catch (\Exception $e)
            {
                $this->logger->warning('Failed to unsubscribe from provider', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage()
                ]);
                // Continue with database deletion even if provider unsubscribe fails
            }

            // Delete from database
            $deleteSql = "DELETE FROM bridge_subscriptions 
                         WHERE subscription_id = :subscription_id AND bridge_type = :bridge_type";
            if ($tenantId !== '')
            {
                $deleteSql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            }

            $stmt = $this->db->prepare($deleteSql);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0)
            {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'Subscription deleted successfully'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            else
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to delete subscription'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to delete subscription', [
                'bridge' => $bridgeName,
                'subscription_id' => $subscriptionId,
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
        try
        {
            // Prefer tenant-scoped health if tenant is resolved
            $tenantId = $request->getAttribute('tenant_id');
            if (!empty($tenantId))
            {
                $bridgesInfo = $this->bridgeManager->getAllBridgesInfoForTenant((string)$tenantId);
            }
            else
            {
                $bridgesInfo = $this->bridgeManager->getAllBridgesInfo();
            }
            $overallHealth = 'healthy';
            $healthyCount = 0;
            $unhealthyCount = 0;

            foreach ($bridgesInfo as $bridgeInfo)
            {
                if (isset($bridgeInfo['health']['status']))
                {
                    if ($bridgeInfo['health']['status'] === 'healthy')
                    {
                        $healthyCount++;
                    }
                    else
                    {
                        $unhealthyCount++;
                        $overallHealth = 'degraded';
                    }
                }
            }

            if ($unhealthyCount === count($bridgesInfo))
            {
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
        }
        catch (\Exception $e)
        {
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
        $bridgeMappings = [
            'outlook' => 'booking_system',
            'booking_system' => 'outlook'
        ];

        return $bridgeMappings[$sourceBridge] ?? null;
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
        try
        {
            if (extension_loaded('redis') && class_exists('\\Redis'))
            {
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
            }
            else
            {
                // Fallback to database queue
                $this->queueToDatabase($sourceBridge, $targetBridge, $webhookData, $tenantId);
            }
        }
        catch (\Exception $e)
        {
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
        try
        {
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
        }
        catch (\Exception $e)
        {
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
        try
        {
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
        }
        catch (\Exception $e)
        {
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
        try
        {
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
        }
        catch (\Exception $e)
        {
            $this->logger->error('Deletion queue processing failed', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Process webhook queue (bridge_sync queue items).
     * POST /bridges/process-webhook-queue
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function processWebhookQueue(Request $request, Response $response, $args)
    {
        try
        {
            $body = json_decode($request->getBody()->getContents(), true) ?? [];
            $batchSize = $body['batch_size'] ?? 50;
            $tenantId = $request->getAttribute('tenant_id');

            // Get pending webhook queue items
            $sql = "
                SELECT id, tenant_id, source_bridge, target_bridge, payload, attempts, created_at
                FROM bridge_queue 
                WHERE queue_type = 'bridge_sync' 
                AND status = 'pending'
            ";
            
            $params = [];
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " ORDER BY priority ASC, created_at ASC LIMIT ?";
            $params[] = $batchSize;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $queueItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processed = 0;
            $errors = 0;
            $errorDetails = [];

            foreach ($queueItems as $item) {
                try {
                    // Mark as processing
                    $updateSql = "UPDATE bridge_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?";
                    $updateStmt = $this->db->prepare($updateSql);
                    $updateStmt->execute([$item['id']]);

                    // Process the webhook payload
                    $payload = json_decode($item['payload'], true);
                    $sourceBridge = $item['source_bridge'];
                    $targetBridge = $item['target_bridge'];
                    $tenantId = $item['tenant_id'];

                    // Process sync operation based on payload
                    if ($payload && isset($payload['resource_id'])) {
                        // This is a webhook event - process it
                        $this->processWebhookEvent($sourceBridge, $targetBridge, $payload, $tenantId);
                    }

                    // Mark as completed
                    $completeSql = "UPDATE bridge_queue SET status = 'completed', processed_at = CURRENT_TIMESTAMP WHERE id = ?";
                    $completeStmt = $this->db->prepare($completeSql);
                    $completeStmt->execute([$item['id']]);

                    $processed++;

                } catch (\Exception $e) {
                    $errors++;
                    $errorDetails[] = [
                        'queue_id' => $item['id'],
                        'error' => $e->getMessage()
                    ];

                    // Mark as failed if max attempts reached, otherwise back to pending
                    $maxAttempts = 3;
                    $newStatus = ($item['attempts'] + 1) >= $maxAttempts ? 'failed' : 'pending';
                    
                    $errorSql = "UPDATE bridge_queue SET status = ?, error_message = ? WHERE id = ?";
                    $errorStmt = $this->db->prepare($errorSql);
                    $errorStmt->execute([$newStatus, $e->getMessage(), $item['id']]);

                    $this->logger->error('Failed to process webhook queue item', [
                        'queue_id' => $item['id'],
                        'attempts' => $item['attempts'] + 1,
                        'max_attempts' => $maxAttempts,
                        'new_status' => $newStatus,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Webhook queue processed',
                'processed' => $processed,
                'errors' => $errors,
                'error_details' => $errorDetails,
                'total_items' => count($queueItems)
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Webhook queue processing failed', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Process webhook queue immediately (internal method for FastCGI finish request).
     *
     * @param string|null $tenantId
     * @param int $batchSize
     * @return void
     */
    private function processWebhookQueueImmediate(?string $tenantId = null, int $batchSize = 1): void
    {
        try
        {
            $maxProcessingTime = intval($_ENV['WEBHOOK_MAX_PROCESSING_TIME'] ?? 10);
            $startTime = time();
            
            // Get the most recent pending item for this tenant
            $sql = "
                SELECT id, source_bridge, target_bridge, payload, attempts, created_at, tenant_id
                FROM bridge_queue 
                WHERE queue_type = 'bridge_sync' 
                AND status = 'pending'
            ";
            
            $params = [];
            if ($tenantId)
            {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " ORDER BY priority ASC, created_at DESC LIMIT ?";
            $params[] = $batchSize;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $queueItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $processed = 0;
            $errors = 0;

            foreach ($queueItems as $item)
            {
                // Check processing time limit
                if (time() - $startTime > $maxProcessingTime)
                {
                    $this->logger->warning('Immediate webhook processing time limit reached', [
                        'processed' => $processed,
                        'time_limit' => $maxProcessingTime
                    ]);
                    break;
                }

                try
                {
                    // Mark as processing
                    $updateSql = "UPDATE bridge_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?";
                    $updateStmt = $this->db->prepare($updateSql);
                    $updateStmt->execute([$item['id']]);

                    // Process the webhook payload
                    $payload = json_decode($item['payload'], true);
                    $this->processWebhookEvent($item['source_bridge'], $item['target_bridge'], $payload, $item['tenant_id']);

                    // Mark as completed
                    $completeSql = "UPDATE bridge_queue SET status = 'completed', processed_at = CURRENT_TIMESTAMP WHERE id = ?";
                    $completeStmt = $this->db->prepare($completeSql);
                    $completeStmt->execute([$item['id']]);

                    $processed++;

                    $this->logger->info('Immediate webhook processing completed', [
                        'queue_id' => $item['id'],
                        'source_bridge' => $item['source_bridge'],
                        'target_bridge' => $item['target_bridge'],
                        'tenant_id' => $item['tenant_id']
                    ]);

                }
                catch (\Exception $e)
                {
                    $errors++;
                    
                    // Mark as failed or back to pending based on attempts
                    $maxAttempts = intval($_ENV['WEBHOOK_MAX_ATTEMPTS'] ?? 3);
                    $newStatus = ($item['attempts'] + 1) >= $maxAttempts ? 'failed' : 'pending';
                    
                    $errorSql = "UPDATE bridge_queue SET status = ?, error_message = ? WHERE id = ?";
                    $errorStmt = $this->db->prepare($errorSql);
                    $errorStmt->execute([$newStatus, $e->getMessage(), $item['id']]);

                    $this->logger->error('Immediate webhook processing failed', [
                        'queue_id' => $item['id'],
                        'error' => $e->getMessage(),
                        'tenant_id' => $item['tenant_id']
                    ]);
                }
            }

            $this->logger->info('Immediate webhook queue processing completed', [
                'processed' => $processed,
                'errors' => $errors,
                'total_items' => count($queueItems),
                'processing_time' => time() - $startTime
            ]);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Immediate webhook queue processing failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);
        }
    }

    /**
     * Process a webhook event from the queue.
     *
     * @param string $sourceBridge
     * @param string $targetBridge  
     * @param array $payload
     * @param string|null $tenantId
     * @return void
     */
    private function processWebhookEvent($sourceBridge, $targetBridge, $payload, $tenantId)
    {

        // Extract resource and event information from payload
        $resourceId = $payload['resource_id'] ?? null;
        $eventId = $payload['event_id'] ?? null;
        $changeType = $payload['change_type'] ?? 'updated';

        if (!$resourceId) {
            throw new \Exception('No resource_id in webhook payload');
        }

        // Determine sync direction and create mapping entry
        if ($changeType === 'deleted') {
            // Handle deletion
            $this->handleEventDeletion($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId);
        } else {
            // Handle create/update - pass the full payload (includes entity_data)
            $this->syncWebhookEventAndManageMapping($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId, $payload);
        }
    }

    /**
     * Sync a webhook event to target bridge and manage the bridge mapping.
     * 
     * This method:
     * 1. Finds the resource mapping between bridges
     * 2. Retrieves event data (from webhook payload or API)
     * 3. Performs the actual sync operation via BridgeManager
     * 4. Creates new mapping for created events or updates existing mapping for updates
     * 
     * @param string $sourceBridge Source bridge name
     * @param string $targetBridge Target bridge name
     * @param string $resourceId Resource/calendar ID
     * @param string $eventId Event ID (composite format for booking system: "event_117905")
     * @param string|null $tenantId Tenant identifier
     * @param array|null $payload Full webhook payload (may include entity_data)
     * @return array Sync results
     * @throws \Exception If sync operation fails
     */
    private function syncWebhookEventAndManageMapping($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId, $payload = null)
    {
        try {
            // For webhook events, we need to use the resource mapping to find target calendar
            // Check both directions for bidirectional mappings (like syncBridges does)
            $mappingSql = "
                SELECT 
                    tenant_id, 
                    source_calendar_id,
                    target_calendar_id, 
                    id as mapping_id,
                    bridge_from,
                    bridge_to,
                    sync_direction
                FROM bridge_resource_mappings 
                WHERE (
                    (bridge_from = ? AND bridge_to = ? AND source_calendar_id = ?) OR
                    (bridge_from = ? AND bridge_to = ? AND target_calendar_id = ?)
                )
                AND is_active = true
            ";

            
            $mappingParams = [
                $sourceBridge, $targetBridge, $resourceId,  // Forward direction
                $targetBridge, $sourceBridge, $resourceId   // Reverse direction
            ];
            if ($tenantId) {
                $mappingSql .= " AND tenant_id = ?";
                $mappingParams[] = $tenantId;
            }

            $mappingStmt = $this->db->prepare($mappingSql);
            $mappingStmt->execute($mappingParams);
            $resourceMapping = $mappingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$resourceMapping) {
                throw new \Exception("No resource mapping found for {$sourceBridge} resource {$resourceId} to {$targetBridge}");
            }

            // Determine the correct source and target calendar IDs based on mapping direction
            if ($resourceMapping['bridge_from'] === $sourceBridge && $resourceMapping['bridge_to'] === $targetBridge) {
                // Forward direction: webhook source matches mapping source
                $sourceCalendarId = $resourceMapping['source_calendar_id'];
                $targetCalendarId = $resourceMapping['target_calendar_id'];
            } else {
                // Reverse direction: webhook source matches mapping target
                $sourceCalendarId = $resourceMapping['target_calendar_id'];
                $targetCalendarId = $resourceMapping['source_calendar_id'];
            }

            $resourceMappingId = $resourceMapping['mapping_id'];
            $tenantId = $resourceMapping['tenant_id'];

            // Get the source bridge instance
            $sourceBridgeInstance = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridge);

            // Check if we have event data in the webhook payload (booking_system webhooks include entity_data)
            $sourceEvent = null;
            if ($payload && isset($payload['original_notification']['entity_data'])) {
                // Use event data from webhook payload - no need to fetch from API!
                $entityData = $payload['original_notification']['entity_data'];
                
                // Add entity_type from webhook to entity_data if not present
                // This ensures proper composite ID creation (e.g., "event_117905" instead of "unknown_117905")
                if (isset($payload['entity_type']) && !isset($entityData['type'])) {
                    $entityData['type'] = $payload['entity_type'];
                }
                
                $this->logger->info('Using event data from webhook payload (no API fetch needed)', [
                    'event_id' => $eventId,
                    'source_bridge' => $sourceBridge,
                    'entity_type' => $payload['entity_type'] ?? 'unknown',
                    'has_entity_data' => true
                ]);
                
                // Transform booking system event data to generic format using the bridge's mapping
                if ($sourceBridge === 'booking_system' && method_exists($sourceBridgeInstance, 'mapBookingEventToGeneric')) {
                    // Use reflection to call private method (or make it public/protected)
                    $reflection = new \ReflectionClass($sourceBridgeInstance);
                    $method = $reflection->getMethod('mapBookingEventToGeneric');
                    $method->setAccessible(true);
                    $sourceEvent = $method->invoke($sourceBridgeInstance, $entityData);
                } else {
                    // For other bridges or if method doesn't exist, use entity_data directly
                    // Assume it's already in a reasonable format
                    $sourceEvent = $entityData;
                }
            }
            
            // Fallback: fetch from API if not in payload
            if (!$sourceEvent) {
                $this->logger->info('Event data not in webhook payload, fetching from API', [
                    'event_id' => $eventId,
                    'source_bridge' => $sourceBridge,
                    'source_calendar_id' => $sourceCalendarId
                ]);
                
                $sourceEvent = $sourceBridgeInstance->getEvent($sourceCalendarId, $eventId);
                
                if (!$sourceEvent) {
                    throw new \Exception("Event {$eventId} not found in {$sourceBridge} calendar {$sourceCalendarId}");
                }
            }

            // Perform the actual sync operation to the target bridge
            $options = [
                'tenant_id' => $tenantId,
                'sync_method' => 'webhook',
                'handle_deletions' => false,
                'skip_updates' => false,
                'dry_run' => false,
                'mapping_config' => [
                    'mapping_id' => $resourceMappingId,
                    'bridge_from' => $sourceBridge,
                    'bridge_to' => $targetBridge,
                ]
            ];

            $this->logger->info('Performing webhook sync operation', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'source_calendar_id' => $sourceCalendarId,
                'target_calendar_id' => $targetCalendarId,
                'source_event_id' => $eventId,
                'tenant_id' => $tenantId,
                'mapping_direction' => $resourceMapping['bridge_from'] . ' -> ' . $resourceMapping['bridge_to'],
                'webhook_resource_id' => $resourceId
            ]);

            // Process the single event directly instead of full date range sync
            $syncResults = $this->bridgeManager->processSingleEventSync(
                $sourceBridge,
                $targetBridge,
                $sourceCalendarId,
                $targetCalendarId,
                $sourceEvent,
                $options
            );

            // Check if sync was successful
            if (!$syncResults['success']) {
                throw new \Exception("Sync operation failed: " . ($syncResults['error'] ?? 'Unknown error'));
            }

            $totalCreated = $syncResults['created'] ?? 0;
            $totalUpdated = $syncResults['updated'] ?? 0;
            $totalErrors = $syncResults['errors'] ?? 0;

            // Extract target event ID from sync results if available
            $targetEventId = $syncResults['target_event_id'] ?? null;

            // Only create NEW mappings for created events, update existing mappings for updates
            if ($totalCreated > 0) {
                // Event was created - insert new mapping
                $mappingStatus = 'completed';
                
                $sql = "
                    INSERT INTO bridge_mappings 
                    (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, sync_status, tenant_id, created_at, updated_at, target_event_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON CONFLICT (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, tenant_id)
                    DO UPDATE SET 
                        sync_status = ?,
                        updated_at = CURRENT_TIMESTAMP,
                        retry_count = 0,
                        error_message = NULL,
                        target_event_id = ?
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $sourceBridge,
                    $targetBridge, 
                    $sourceCalendarId,
                    $targetCalendarId,
                    $eventId,
                    $mappingStatus,
                    $tenantId,
                    $targetEventId,
                    // ON CONFLICT values
                    $mappingStatus,
                    $targetEventId
                ]);

                $this->logger->info('Created new bridge mapping after successful event creation', [
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'source_event_id' => $eventId,
                    'target_event_id' => $targetEventId,
                    'tenant_id' => $tenantId
                ]);
            } elseif ($totalUpdated > 0) {
                // Event was updated - only update existing mapping if it exists
                $mappingStatus = 'completed';
                
                $sql = "
                    UPDATE bridge_mappings 
                    SET sync_status = ?,
                        updated_at = CURRENT_TIMESTAMP,
                        retry_count = 0,
                        error_message = NULL,
                        target_event_id = ?
                    WHERE source_bridge = ?
                    AND target_bridge = ?
                    AND source_calendar_id = ?
                    AND target_calendar_id = ?
                    AND source_event_id = ?
                    AND tenant_id IS NOT DISTINCT FROM ?
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $mappingStatus,
                    $targetEventId,
                    $sourceBridge,
                    $targetBridge,
                    $sourceCalendarId,
                    $targetCalendarId,
                    $eventId,
                    $tenantId
                ]);

                if ($stmt->rowCount() > 0) {
                    $this->logger->info('Updated existing bridge mapping after successful event update', [
                        'source_bridge' => $sourceBridge,
                        'target_bridge' => $targetBridge,
                        'source_event_id' => $eventId,
                        'target_event_id' => $targetEventId,
                        'tenant_id' => $tenantId
                    ]);
                } else {
                    $this->logger->warning('No existing mapping found to update after event update', [
                        'source_bridge' => $sourceBridge,
                        'target_bridge' => $targetBridge,
                        'source_event_id' => $eventId,
                        'tenant_id' => $tenantId
                    ]);
                }
            }

            return $syncResults;

        } catch (\Exception $e) {
            $this->logger->error('Failed to sync and create bridge mapping', [
                'error' => $e->getMessage(),
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'webhook_resource_id' => $resourceId,
                'event_id' => $eventId,
                'tenant_id' => $tenantId
            ]);
            throw $e;
        }
    }

    /**
     * Handle event deletion from webhook based on ownership rules.
     * 
     * Ownership logic (sync_direction):
     * - source_to_target: Source owns events → DELETE from target
     * - target_to_source: Target owns events → SKIP deletion (target change only)
     * - bidirectional: Both can modify → DELETE from target
     * 
     * @param string $sourceBridge Source bridge name
     * @param string $targetBridge Target bridge name
     * @param string $resourceId Source resource/calendar ID
     * @param string $eventId Source event ID
     * @param string|null $tenantId Tenant identifier
     * @return void
     * @throws \Exception If deletion operation fails
     */
    private function handleEventDeletion($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId)
    {
        try {
            // Get the resource mapping to check sync_direction and ownership
            $mappingSql = "
                SELECT 
                    brm.id as mapping_id,
                    brm.source_calendar_id,
                    brm.target_calendar_id,
                    brm.bridge_from,
                    brm.bridge_to,
                    brm.sync_direction,
                    bm.target_event_id,
                    bm.target_calendar_id as mapped_target_calendar
                FROM bridge_resource_mappings brm
                LEFT JOIN bridge_mappings bm ON (
                    bm.source_bridge = brm.bridge_from
                    AND bm.target_bridge = brm.bridge_to
                    AND bm.source_calendar_id = brm.source_calendar_id
                    AND bm.target_calendar_id = brm.target_calendar_id
                    AND bm.source_event_id = ?
                )
                WHERE (
                    (brm.bridge_from = ? AND brm.bridge_to = ? AND brm.source_calendar_id = ?) OR
                    (brm.bridge_from = ? AND brm.bridge_to = ? AND brm.target_calendar_id = ?)
                )
                AND brm.is_active = true
            ";
            
            $mappingParams = [
                $eventId,  // For bridge_mappings JOIN
                $sourceBridge, $targetBridge, $resourceId,  // Forward direction
                $targetBridge, $sourceBridge, $resourceId   // Reverse direction
            ];
            
            if ($tenantId) {
                $mappingSql .= " AND brm.tenant_id = ?";
                $mappingParams[] = $tenantId;
            }

            $mappingStmt = $this->db->prepare($mappingSql);
            $mappingStmt->execute($mappingParams);
            $resourceMapping = $mappingStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$resourceMapping) {
                $this->logger->warning('No resource mapping found for deletion - skipping', [
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'resource_id' => $resourceId,
                    'event_id' => $eventId,
                    'tenant_id' => $tenantId
                ]);
                return;
            }

            // Determine if we need to check ownership direction
            $syncDirection = $resourceMapping['sync_direction'] ?? 'bidirectional';
            $targetEventId = $resourceMapping['target_event_id'];
            $targetCalendarId = $resourceMapping['mapped_target_calendar'] ?? $resourceMapping['target_calendar_id'];

            // Determine if source bridge owns the event
            $sourceOwnsEvent = false;
            if ($resourceMapping['bridge_from'] === $sourceBridge && $resourceMapping['bridge_to'] === $targetBridge) {
                // Forward direction: webhook source matches mapping source
                $sourceOwnsEvent = in_array($syncDirection, ['source_to_target', 'bidirectional']);
            } elseif ($resourceMapping['bridge_from'] === $targetBridge && $resourceMapping['bridge_to'] === $sourceBridge) {
                // Reverse direction: webhook source matches mapping target
                // In this case, source is actually the "target" in the mapping
                $sourceOwnsEvent = in_array($syncDirection, ['target_to_source', 'bidirectional']);
            }

            if (!$sourceOwnsEvent) {
                $this->logger->info('Source does not own event - skipping deletion from target', [
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'sync_direction' => $syncDirection,
                    'event_id' => $eventId,
                    'tenant_id' => $tenantId
                ]);
                
                // Still mark mapping as cancelled for tracking purposes
                if ($targetEventId) {
                    $this->markMappingCancelled($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId);
                }
                return;
            }

            // Source owns the event - delete from target system
            if (!$targetEventId) {
                $this->logger->warning('No target event ID found in mapping - cannot delete', [
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'event_id' => $eventId,
                    'tenant_id' => $tenantId
                ]);
                return;
            }

            // Get target bridge instance and delete the event
            $targetBridgeInstance = $this->bridgeManager->getBridgeForTenant($tenantId, $targetBridge);
            
            $this->logger->info('Deleting event from target bridge (source owns event)', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'source_event_id' => $eventId,
                'target_event_id' => $targetEventId,
                'target_calendar_id' => $targetCalendarId,
                'sync_direction' => $syncDirection,
                'tenant_id' => $tenantId
            ]);

            // Delete the event from target system
            $targetBridgeInstance->deleteEvent($targetCalendarId, $targetEventId);

            // Mark mapping as cancelled after successful deletion
            $this->markMappingCancelled($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId);

            $this->logger->info('Successfully deleted event from target bridge', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'source_event_id' => $eventId,
                'target_event_id' => $targetEventId,
                'tenant_id' => $tenantId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to handle event deletion', [
                'error' => $e->getMessage(),
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'resource_id' => $resourceId,
                'event_id' => $eventId,
                'tenant_id' => $tenantId
            ]);
            throw $e;
        }
    }

    /**
     * Mark a bridge mapping as cancelled.
     * 
     * @param string $sourceBridge Source bridge name
     * @param string $targetBridge Target bridge name
     * @param string $resourceId Source resource/calendar ID
     * @param string $eventId Source event ID
     * @param string|null $tenantId Tenant identifier
     * @return void
     */
    private function markMappingCancelled($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId)
    {
        $sql = "
            UPDATE bridge_mappings 
            SET sync_status = 'cancelled', 
                updated_at = CURRENT_TIMESTAMP
            WHERE source_bridge = ? 
            AND target_bridge = ?
            AND source_calendar_id = ? 
            AND source_event_id = ?
        ";
        
        $params = [$sourceBridge, $targetBridge, $resourceId, $eventId];
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $this->logger->info('Marked mapping as cancelled', [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'resource_id' => $resourceId,
            'event_id' => $eventId,
            'rows_affected' => $stmt->rowCount(),
            'tenant_id' => $tenantId
        ]);
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
        try
        {
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
            if (isset($result['resources']) && isset($result['metadata']))
            {
                $resources = $result['resources'];
                $metadata = $result['metadata'];
            }
            else
            {
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
            if (isset($metadata['total_records']))
            {
                $responseData['total_records'] = $metadata['total_records'];
            }

            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0)
            {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($resources)
                ];

                // Add total_records to pagination if available
                if (isset($metadata['total_records']))
                {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }

            $response->getBody()->write(json_encode($responseData));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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
        try
        {
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
            if (isset($result['resources']) && isset($result['metadata']))
            {
                $groups = $result['resources'];
                $metadata = $result['metadata'];
            }
            else
            {
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
            if (isset($metadata['total_records']))
            {
                $responseData['total_records'] = $metadata['total_records'];
            }

            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0)
            {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($groups)
                ];

                // Add total_records to pagination if available
                if (isset($metadata['total_records']))
                {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }

            $response->getBody()->write(json_encode($responseData));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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
        try
        {
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
            if (isset($result['calendar_items']) && isset($result['metadata']))
            {
                $calendarItems = $result['calendar_items'];
                $metadata = $result['metadata'];
            }
            else
            {
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
            if (isset($metadata['total_records']))
            {
                $responseData['total_records'] = $metadata['total_records'];
            }

            // Add pagination info if pagination was requested
            if ($limit > 0 || $offset > 0)
            {
                $responseData['pagination'] = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned_count' => count($calendarItems)
                ];

                // Add total_records to pagination if available
                if (isset($metadata['total_records']))
                {
                    $responseData['pagination']['total_records'] = $metadata['total_records'];
                }
            }

            $response->getBody()->write(json_encode($responseData));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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
        try
        {
            $bridgeName = $args['bridgeName'];

            // Get bridge instance
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);

            // Get session diagnostics if the bridge supports it
            $diagnostics = [];
            if (method_exists($bridge, 'getSessionDiagnostics'))
            {
                $diagnostics = $bridge->getSessionDiagnostics();
            }
            else
            {
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
        }
        catch (\Exception $e)
        {
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
        try
        {
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
        }
        catch (\Exception $e)
        {
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
        try
        {
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
        }
        catch (\Exception $e)
        {
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
        try
        {
            $bridgeName = $args['bridgeName'] ?? null;

            if ($bridgeName)
            {
                // Get stats for specific bridge
                $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
                $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);
                $stats = $bridge->getSyncStats();

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'bridge_name' => $bridgeName,
                    'stats' => $stats
                ]));
            }
            else
            {
                // Get stats for all bridges
                $allStats = $this->bridgeManager->getAllSyncStats();

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'all_bridge_stats' => $allStats
                ]));
            }

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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
        try
        {
            $bridgeName = $args['bridgeName'] ?? null;

            if ($bridgeName)
            {
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
            }
            else
            {
                // Get cancelled events for all bridges
                $allCancelledEvents = $this->bridgeManager->getAllCancelledEvents();

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'all_cancelled_events' => $allCancelledEvents
                ]));
            }

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
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
     * Create a new event on a bridge resource.
     * POST /bridges/{bridgeName}/resources/{resourceId}/events
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Must include bridgeName and resourceId
     * @return Response
     */
    public function createEvent(Request $request, Response $response, array $args): Response
    {
        try
        {
            $bridgeName = $args['bridgeName'];
            $resourceId = $args['resourceId'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data)
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON data'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Validate required fields
            if (empty($data['title']) || empty($data['start_time']) || empty($data['end_time']))
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: title, start_time, end_time'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Transform admin UI format to generic event format expected by bridges
            $eventData = $this->transformAdminDataToGenericEvent($data, $bridge);

            // Create event through the bridge
            $eventId = $bridge->createEvent($resourceId, $eventData);

            $response->getBody()->write(json_encode([
                'success' => true,
                'bridge' => $bridgeName,
                'resource_id' => $resourceId,
                'event_id' => $eventId,
                'message' => 'Event created successfully'
            ]));

            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to create event', [
                'bridge' => $bridgeName ?? 'unknown',
                'resource_id' => $resourceId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to create event: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Update an existing event on a bridge.
     * PUT /bridges/{bridgeName}/events/{eventId}
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Must include bridgeName and eventId
     * @return Response
     */
    public function updateEvent(Request $request, Response $response, array $args): Response
    {
        try
        {
            $bridgeName = $args['bridgeName'];
            $eventId = $args['eventId'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data)
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON data'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Get resource ID from the request data
            $resourceId = $data['resource_id'] ?? null;
            if (!$resourceId)
            {
                // Fallback: try to find resource ID from existing event mappings
                $resourceId = $this->findResourceIdForEvent($bridgeName, $eventId, $tenantId);
            }
            
            // Transform admin UI format to generic event format expected by bridges
            $eventData = $this->transformAdminDataToGenericEvent($data, $bridge);
            
            // Update event through the bridge
            $success = $bridge->updateEvent($resourceId, $eventId, $eventData);

            if ($success)
            {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'bridge' => $bridgeName,
                    'event_id' => $eventId,
                    'message' => 'Event updated successfully'
                ]));
            }
            else
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to update event'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to update event', [
                'bridge' => $bridgeName ?? 'unknown',
                'event_id' => $eventId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to update event: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Delete an event from a bridge.
     * DELETE /bridges/{bridgeName}/events/{eventId}
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Must include bridgeName and eventId
     * @return Response
     */
    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        try
        {
            $bridgeName = $args['bridgeName'];

            if($bridgeName === 'booking_system') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Event deletion is not supported for the booking_system bridge'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $eventId = $args['eventId'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? 'default');
            $bridge = $this->bridgeManager->getBridgeForTenant($tenantId, $bridgeName);

            // Get resource ID from URL path (if route includes it) or from query params
            $resourceId = $args['resourceId'] ?? null;
            if (!$resourceId) {
                $queryParams = $request->getQueryParams();
                $resourceId = $queryParams['resource_id'] ?? null;
            }
            
            if (!$resourceId) {
                // Fallback: try to find resource ID from existing event mappings
                $resourceId = $this->findResourceIdForEvent($bridgeName, $eventId, $tenantId);
            }
            
            if (!$resourceId)
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Unable to determine resource ID for event. Please provide resource_id in query params or ensure proper event mapping exists.'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Delete event through the bridge
            $success = $bridge->deleteEvent($resourceId, $eventId);

            if ($success)
            {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'bridge' => $bridgeName,
                    'event_id' => $eventId,
                    'message' => 'Event deleted successfully'
                ]));
            }
            else
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to delete event'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to delete event', [
                'bridge' => $bridgeName ?? 'unknown',
                'event_id' => $eventId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to delete event: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Helper method to find resource ID for an existing event.
     * This looks up the event in bridge mappings to determine its resource.
     *
     * @param string $bridgeName
     * @param string $eventId
     * @param string $tenantId
     * @return string|null
     */
    private function findResourceIdForEvent(string $bridgeName, string $eventId, string $tenantId): ?string
    {
        try
        {
            // Look for the event in bridge_mappings table
            $sql = "SELECT source_calendar_id, target_calendar_id, source_bridge, target_bridge 
                    FROM bridge_mappings 
                    WHERE (source_event_id = :event_id OR target_event_id = :event_id)
                    AND (source_bridge = :bridge_name OR target_bridge = :bridge_name)
                    AND (tenant_id = :tenant_id OR tenant_id IS NULL)
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'event_id' => $eventId,
                'bridge_name' => $bridgeName,
                'tenant_id' => $tenantId
            ]);

            $bridgeMapping = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($bridgeMapping)
            {
                // Return the appropriate calendar ID based on which bridge we're working with
                if ($bridgeMapping['source_bridge'] === $bridgeName)
                {
                    return $bridgeMapping['source_calendar_id'];
                }
                elseif ($bridgeMapping['target_bridge'] === $bridgeName)
                {
                    return $bridgeMapping['target_calendar_id'];
                }
            }

            return null;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to find resource ID for event', [
                'bridge' => $bridgeName,
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Transform admin UI event data to generic event format expected by bridges.
     * 
     * @param array $adminData Event data from admin UI
     * @param \App\Bridge\AbstractCalendarBridge $bridge Bridge instance for timezone configuration
     * @return array Generic event data for bridge processing
     */
    private function transformAdminDataToGenericEvent(array $adminData, $bridge = null): array
    {
        $genericEvent = [];
        
        // Map admin UI fields to generic event fields
        $fieldMappings = [
            'title' => 'subject',
            'start_time' => 'start', 
            'end_time' => 'end',
            'description' => 'description',
            'location' => 'location'
        ];
        
        foreach ($fieldMappings as $adminField => $genericField)
        {
            if (isset($adminData[$adminField]))
            {
                $genericEvent[$genericField] = $adminData[$adminField];
            }
        }
        
        // Get timezone from bridge configuration, fall back to admin data, then UTC
        if ($bridge && method_exists($bridge, 'getTimezone'))
        {
            $genericEvent['timezone'] = $bridge->getTimezone();
        }
        else
        {
            $genericEvent['timezone'] = $adminData['timezone'] ?? 'UTC';
        }
        
        // Convert datetime strings to proper format if needed
        if (isset($genericEvent['start']))
        {
            $genericEvent['start'] = $this->normalizeDateTimeFormat($genericEvent['start']);
        }
        if (isset($genericEvent['end']))
        {
            $genericEvent['end'] = $this->normalizeDateTimeFormat($genericEvent['end']);
        }
        
        // Add metadata to indicate this is from admin UI
        $genericEvent['source'] = 'admin_ui';
        $genericEvent['created_via'] = 'bridge_controller';
        
        return $genericEvent;
    }

    /**
     * Normalize datetime format for bridge consumption.
     * 
     * @param string $datetime
     * @return string
     */
    private function normalizeDateTimeFormat(string $datetime): string
    {
        try
        {
            // Parse the datetime and convert to ISO 8601 format
            $dt = new \DateTime($datetime);
            return $dt->format('c'); // ISO 8601 format (e.g., 2025-09-17T10:00:00+00:00)
        }
        catch (\Exception $e)
        {
            // If parsing fails, return the original string
            return $datetime;
        }
    }
}
