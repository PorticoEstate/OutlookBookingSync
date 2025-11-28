<?php

namespace App\Services;

use App\Repository\BridgeQueueRepository;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeSubscriptionRepository;
use Psr\Log\LoggerInterface;

class WebhookService
{
    private $logger;
    private $bridgeManager;
    private $queueRepository;
    private $resourceRepository;
    private $mappingRepository;
    private $subscriptionRepository;
    private $syncOrchestrator;

    public function __construct(
        LoggerInterface $logger,
        BridgeManager $bridgeManager,
        BridgeQueueRepository $queueRepository,
        BridgeResourceRepository $resourceRepository,
        BridgeMappingRepository $mappingRepository,
        BridgeSubscriptionRepository $subscriptionRepository,
        SyncOrchestrator $syncOrchestrator
    ) {
        $this->logger = $logger;
        $this->bridgeManager = $bridgeManager;
        $this->queueRepository = $queueRepository;
        $this->resourceRepository = $resourceRepository;
        $this->mappingRepository = $mappingRepository;
        $this->subscriptionRepository = $subscriptionRepository;
        $this->syncOrchestrator = $syncOrchestrator;
    }

    /**
     * Handle incoming webhook request.
     * 
     * @param string $bridgeName
     * @param array $body
     * @param array $queryParams
     * @param string $tenantId
     * @return array Response data including status and any immediate output
     */
    public function handleWebhook(string $bridgeName, array $body, array $queryParams, string $tenantId): array
    {
        // 1. Validation
        $validationResult = $this->validateWebhook($bridgeName, $body, $queryParams, $tenantId);
        if ($validationResult['handled']) {
            return $validationResult;
        }

        $this->logger->info('Webhook received', [
            'bridge' => $bridgeName,
            'data' => $body
        ]);

        // 2. Determine target bridge
        $targetBridge = $this->determineTargetBridge($bridgeName);

        // 3. Process and Queue
        if ($bridgeName === 'outlook' && isset($body['value'])) {
            // Process deletion checks first (if any logic exists for that)
            // $this->processMicrosoftGraphNotifications($body['value']); // TODO: Move this logic if needed

            foreach ($body['value'] as $notification) {
                $transformedPayload = $this->transformOutlookNotification($notification, $tenantId);
                if ($transformedPayload) {
                    $this->queueSyncOperation($bridgeName, $targetBridge, $transformedPayload, $tenantId);
                }
            }
        } elseif ($bridgeName === 'booking_system') {
            $notifications = [];
            if (isset($body['value']) && is_array($body['value'])) {
                $notifications = $body['value'];
            } elseif (isset($body['entity_type']) || isset($body['entityId'])) {
                $notifications = [$body];
            } else {
                $this->logger->warning('Unknown booking system notification format', [
                    'body' => $body,
                    'tenant_id' => $tenantId
                ]);
            }

            foreach ($notifications as $notification) {
                $transformedPayload = $this->transformBookingSystemNotification($notification, $tenantId);
                if ($transformedPayload) {
                    $this->queueSyncOperation($bridgeName, $targetBridge, $transformedPayload, $tenantId);
                }
            }
        } else {
            // For other bridges, queue the raw payload
            $this->queueSyncOperation($bridgeName, $targetBridge, $body, $tenantId);
        }

        return [
            'handled' => true,
            'success' => true,
            'message' => 'Webhook processed and sync queued',
            'bridge' => $bridgeName,
            'target_bridge' => $targetBridge,
            'status_code' => 202
        ];
    }

    /**
     * Process webhook queue immediately (internal method for FastCGI finish request).
     *
     * @param string|null $tenantId
     * @param int $batchSize
     * @return void
     */
    public function processWebhookQueueImmediate(?string $tenantId = null, int $batchSize = 1): void
    {
        try
        {
            $maxProcessingTime = intval($_ENV['WEBHOOK_MAX_PROCESSING_TIME'] ?? 10);
            $startTime = time();
            
            // Get the most recent pending item for this tenant
            $queueItems = $this->queueRepository->findPendingItems('bridge_sync', $batchSize, $tenantId, 'DESC');

            $processed = 0;
            $errors = 0;

            foreach ($queueItems as $item)
            {
                // Check processing time limit
                if (time() - $startTime > $maxProcessingTime)
                {
                    $this->logger->warning('Immediate webhook processing time limit reached', [
                        'processed' => $processed,
                        'limit' => $maxProcessingTime
                    ]);
                    break;
                }

                try
                {
                    // Mark as processing
                    $this->queueRepository->markProcessing($item['id']);

                    // Process the webhook payload
                    $payload = json_decode($item['payload'], true);
                    $sourceBridge = $item['source_bridge'];
                    $targetBridge = $item['target_bridge'];
                    $tenantId = $item['tenant_id'];

                    // Process sync operation based on payload
                    if ($payload && isset($payload['resource_id']))
                    {
                        // This is a webhook event - process it
                        $this->processWebhookEvent($sourceBridge, $targetBridge, $payload, $tenantId);
                    }

                    // Mark as completed
                    $this->queueRepository->markCompleted($item['id']);

                    $processed++;
                }
                catch (\Exception $e)
                {
                    $errors++;
                    // Mark as failed if max attempts reached, otherwise back to pending
                    $maxAttempts = 3;
                    $newStatus = ($item['attempts'] + 1) >= $maxAttempts ? 'failed' : 'pending';
                    
                    $this->queueRepository->updateStatus($item['id'], $newStatus, $e->getMessage());

                    $this->logger->error('Failed to process immediate webhook queue item', [
                        'queue_id' => $item['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        catch (\Exception $e)
        {
            $this->logger->error('Immediate webhook queue processing failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Process a batch of webhook queue items.
     *
     * @param int $batchSize
     * @param string|null $tenantId
     * @return array Processing statistics
     */
    public function processWebhookQueueBatch(int $batchSize = 50, ?string $tenantId = null): array
    {
        // Get pending webhook queue items
        $queueItems = $this->queueRepository->findPendingItems('bridge_sync', $batchSize, $tenantId);

        $processed = 0;
        $errors = 0;
        $errorDetails = [];

        foreach ($queueItems as $item) {
            try {
                // Mark as processing
                $this->queueRepository->markProcessing($item['id']);

                // Process the webhook payload
                $payload = json_decode($item['payload'], true);
                $sourceBridge = $item['source_bridge'];
                $targetBridge = $item['target_bridge'];
                $itemTenantId = $item['tenant_id'];

                // Process sync operation based on payload
                if ($payload && isset($payload['resource_id'])) {
                    // This is a webhook event - process it
                    $this->processWebhookEvent($sourceBridge, $targetBridge, $payload, $itemTenantId);
                }

                // Mark as completed
                $this->queueRepository->markCompleted($item['id']);

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
                
                $this->queueRepository->updateStatus($item['id'], $newStatus, $e->getMessage());

                $this->logger->error('Failed to process webhook queue item', [
                    'queue_id' => $item['id'],
                    'attempts' => $item['attempts'] + 1,
                    'max_attempts' => $maxAttempts,
                    'new_status' => $newStatus,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'error_details' => $errorDetails,
            'total_items' => count($queueItems)
        ];
    }

    private function validateWebhook(string $bridgeName, array $body, array $queryParams, string $tenantId): array
    {
        // Microsoft Graph validation token
        if (isset($queryParams['validationToken'])) {
            return [
                'handled' => true,
                'output' => $queryParams['validationToken'],
                'content_type' => 'text/plain',
                'status_code' => 200
            ];
        }

        // Booking system challenge
        if ($bridgeName === 'booking_system' && isset($queryParams['challenge'])) {
            $this->logger->info('Booking system webhook validation handshake', ['challenge' => $queryParams['challenge']]);
            return [
                'handled' => true,
                'output' => $queryParams['challenge'],
                'content_type' => 'text/plain',
                'status_code' => 200
            ];
        }

        // Outlook Client State
        if ($bridgeName === 'outlook') {
            $expectedClientState = $_ENV['GRAPH_CLIENT_STATE'] ?? null;
            $clientState = $body['value'][0]['clientState'] ?? null;
            if ($expectedClientState && $clientState && !hash_equals($expectedClientState, $clientState)) {
                $this->logger->warning('Webhook clientState mismatch', ['expected' => '***', 'got' => $clientState]);
                return [
                    'handled' => true,
                    'success' => false,
                    'error' => 'Invalid clientState',
                    'status_code' => 401
                ];
            }
        }

        // Booking System Client State
        if ($bridgeName === 'booking_system') {
            $clientState = $body['client_state'] ?? null;
            if ($clientState) {
                try {
                    $bookingBridge = $this->bridgeManager->getBridgeForTenant($tenantId, 'booking_system');
                    $clientSecret = $bookingBridge->getConfig()['webhook_client_secret'] ?? $_ENV['WEBHOOK_CLIENT_SECRET'] ?? null;
                    
                    if ($clientSecret) {
                        $payload = sprintf('booking-bridge-%s-%s', $tenantId, $clientSecret);
                        $expectedClientState = hash('sha256', $payload);
                        
                        if (!hash_equals($expectedClientState, $clientState)) {
                            $this->logger->warning('Booking system webhook clientState mismatch', [
                                'tenant_id' => $tenantId
                            ]);
                            return [
                                'handled' => true,
                                'success' => false,
                                'error' => 'Invalid clientState',
                                'status_code' => 401
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to validate booking system client state', [
                        'error' => $e->getMessage(),
                        'tenant_id' => $tenantId
                    ]);
                }
            }
        }

        return ['handled' => false];
    }

    private function determineTargetBridge($sourceBridge)
    {
        $bridgeMappings = [
            'outlook' => 'booking_system',
            'booking_system' => 'outlook'
        ];
        return $bridgeMappings[$sourceBridge] ?? null;
    }

    private function queueSyncOperation($sourceBridge, $targetBridge, $webhookData, ?string $tenantId = null)
    {
        try {
            $this->queueRepository->enqueue(
                'bridge_sync',
                $sourceBridge,
                $targetBridge,
                $webhookData,
                1,
                $tenantId
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to enqueue sync operation', [
                'error' => $e->getMessage(),
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge
            ]);
        }
    }

    private function transformOutlookNotification($notification, $tenantId)
    {
        try {
            $changeType = $notification['changeType'] ?? null;
            $resourceUrl = $notification['resource'] ?? null;
            $eventId = $notification['resourceData']['id'] ?? null;

            if (!$resourceUrl || !$eventId) {
                return null;
            }

            if (preg_match('/users\/([^\/]+)\/events/i', $resourceUrl, $matches)) {
                $userGuid = $matches[1];
                $calendarId = $this->resolveUserGuidToEmail($userGuid, $tenantId);
                
                if (!$calendarId) {
                    return null;
                }
                
                return [
                    'resource_id' => $calendarId,
                    'event_id' => $eventId,
                    'change_type' => $changeType,
                    'timestamp' => date('c'),
                    'source' => 'outlook_webhook',
                    'original_notification' => $notification
                ];
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to transform Outlook notification', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function transformBookingSystemNotification($notification, $tenantId)
    {
        // Simplified version - assuming structure based on BridgeController
        $entityType = $notification['entity_type'] ?? 'event';
        $changeType = $notification['change_type'] ?? 'updated';
        $entityId = $notification['entity_id'] ?? $notification['entityId'] ?? null;
        $resourceId = $notification['resource_id'] ?? $notification['resourceId'] ?? null;

        if (!$entityId || !$resourceId) {
            return null;
        }

        // Transform to internal format matching Outlook notification structure
        // Create composite event ID with entity_type prefix (e.g., "event_117905")
        $compositeEventId = ($entityType ? $entityType . '_' : '') . $entityId;
        return [
            'resource_id' => $resourceId,
            'event_id' => $compositeEventId,
            'change_type' => $changeType,
            'entity_type' => $entityType,
            'timestamp' => date('c'),
            'source' => 'booking_system_webhook',
            'original_notification' => $notification
        ];
    }

    private function resolveUserGuidToEmail(string $userGuid, ?string $tenantId = null): ?string
    {
        try {
            $outlookBridge = $this->bridgeManager->getBridgeForTenant($tenantId ?: 'default', 'outlook');
            return $outlookBridge->resolveUserGuidToEmail($userGuid);
        } catch (\Exception $e) {
            $this->logger->error('Failed to resolve user GUID to email', ['error' => $e->getMessage()]);
            return null;
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
    public function processWebhookEvent($sourceBridge, $targetBridge, $payload, $tenantId)
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
     */
    private function syncWebhookEventAndManageMapping($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId, $payload = null)
    {
        try {
            // For webhook events, we need to use the resource mapping to find target calendar
            // Check both directions for bidirectional mappings (like syncBridges does)
            $resourceMapping = $this->resourceRepository->findMappingForWebhook($sourceBridge, $targetBridge, $resourceId, $tenantId);

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
                if ($sourceBridge === 'booking_system' && $sourceBridgeInstance instanceof \App\Bridge\BookingSystemBridge) {
                    // Use public method if available instead of Reflection
                    $sourceEvent = $sourceBridgeInstance->mapBookingEventToGeneric($entityData);
                } else {
                    // For other bridges or if method doesn't exist/isn't public, use entity_data directly
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
            $syncResults = $this->syncOrchestrator->processSingleEventSync(
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

            // Extract target event ID from sync results if available
            $targetEventId = $syncResults['target_event_id'] ?? null;

            // Only create NEW mappings for created events, update existing mappings for updates
            if ($totalCreated > 0) {
                // Event was created - insert new mapping
                $this->mappingRepository->createOrUpdateWithTargetEventId([
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'source_calendar_id' => $sourceCalendarId,
                    'target_calendar_id' => $targetCalendarId,
                    'source_event_id' => $eventId,
                    'sync_status' => 'completed',
                    'tenant_id' => $tenantId,
                    'target_event_id' => $targetEventId
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
                $updatedRows = $this->mappingRepository->updateStatusAndTargetEventId([
                    'sync_status' => 'completed',
                    'target_event_id' => $targetEventId,
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'source_calendar_id' => $sourceCalendarId,
                    'target_calendar_id' => $targetCalendarId,
                    'source_event_id' => $eventId,
                    'tenant_id' => $tenantId
                ]);

                if ($updatedRows > 0) {
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
     */
    private function handleEventDeletion($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId)
    {
        try {
            // Find the resource mapping and the specific event mapping
            $resourceMapping = $this->resourceRepository->findMappingForDeletion($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId);

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
                'event_id' => $eventId,
                'target_event_id' => $targetEventId
            ]);
            
            $success = $targetBridgeInstance->deleteEvent($targetCalendarId, $targetEventId);
            
            if ($success) {
                $this->logger->info('Successfully deleted event from target bridge', [
                    'target_bridge' => $targetBridge,
                    'target_event_id' => $targetEventId
                ]);
                
                // Mark mapping as cancelled
                $this->markMappingCancelled($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId);
            } else {
                $this->logger->error('Failed to delete event from target bridge', [
                    'target_bridge' => $targetBridge,
                    'target_event_id' => $targetEventId
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to handle event deletion', [
                'error' => $e->getMessage(),
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'event_id' => $eventId
            ]);
        }
    }

    private function markMappingCancelled($sourceBridge, $targetBridge, $resourceId, $eventId, $tenantId)
    {
        try {
            $this->mappingRepository->markAsCancelled(
                $sourceBridge,
                $targetBridge,
                $resourceId,
                $eventId,
                $tenantId
            );
            
            $this->logger->info('Marked mapping as cancelled', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'resource_id' => $resourceId,
                'event_id' => $eventId,
                'tenant_id' => $tenantId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark mapping as cancelled', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Renew expiring webhook subscriptions.
     */
    public function renewSubscriptions(string $bridge = '', int $minutes = 1440, int $limit = 50, string $tenantId = '', string $subscriptionId = ''): array
    {
        $rows = $this->subscriptionRepository->findExpiring($minutes, $limit, $bridge ?: null, $tenantId ?: null, $subscriptionId ?: null);

        $renewed = [];
        $failed = [];
        $recreated = [];

        if (empty($rows)) {
            return [
                'checked' => 0,
                'renewed' => [],
                'recreated' => [],
                'failed' => [],
                'summary' => ['total_checked' => 0, 'success_count' => 0]
            ];
        }

        $prevSubscriptionTenantId = null;
        $bridgeInstance = null;

        foreach ($rows as $row) {
            $subscriptionTenantId = $row['tenant_id'];
            $subscriptionBridge = $row['bridge_type'];

            if (!$tenantId || $subscriptionTenantId !== $prevSubscriptionTenantId) {
                $bridgeInstance = $this->bridgeManager->getBridgeForTenant($subscriptionTenantId, $subscriptionBridge);
            }
            $prevSubscriptionTenantId = $row['tenant_id'];

            $expiresAt = $row['expires_at'];
            $isExpired = strtotime($expiresAt) < time();

            if ($isExpired) {
                // Expired - recreate
                $this->logger->info('Subscription expired, recreating', [
                    'subscription_id' => $row['subscription_id'],
                    'tenant_id' => $subscriptionTenantId
                ]);

                $this->subscriptionRepository->deactivate($row['subscription_id'], $subscriptionTenantId);

                if (method_exists($bridgeInstance, 'subscribeToChanges')) {
                    try {
                        $webhookUrl = $row['webhook_url'];
                        if (!$webhookUrl) {
                            $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
                            $webhookUrl = "{$baseUrl}/bridges/webhook/{$subscriptionBridge}?tenant_id={$subscriptionTenantId}";
                        }

                        $newSubscriptionId = $bridgeInstance->subscribeToChanges(
                            $row['calendar_id'],
                            $webhookUrl,
                            $row['subscription_id']
                        );

                        $recreated[] = [
                            'success' => true,
                            'old_subscription_id' => $row['subscription_id'],
                            'new_subscription_id' => $newSubscriptionId,
                            'calendar_id' => $row['calendar_id']
                        ];
                    } catch (\Exception $e) {
                        $failed[] = [
                            'subscription_id' => $row['subscription_id'],
                            'error' => $e->getMessage()
                        ];
                    }
                } else {
                    $failed[] = ['subscription_id' => $row['subscription_id'], 'error' => 'Bridge does not support subscription creation'];
                }
            } else {
                // Renew
                if (method_exists($bridgeInstance, 'renewSubscription')) {
                    $result = $bridgeInstance->renewSubscription($row['subscription_id']);
                    if (!empty($result['success'])) {
                        $renewed[] = $result;
                        $this->subscriptionRepository->updateExpiration(
                            $row['subscription_id'],
                            $result['expirationDateTime']
                        );
                    } else {
                        // Check if 404/not found
                        $errorMessage = $result['error'] ?? '';
                        if (stripos($errorMessage, '404') !== false || 
                            stripos($errorMessage, 'not found') !== false ||
                            (stripos($errorMessage, 'subscription') !== false && stripos($errorMessage, 'does not exist') !== false)) {
                            
                            // Recreate
                            $this->subscriptionRepository->deactivate($row['subscription_id'], $subscriptionTenantId);
                            
                            if (method_exists($bridgeInstance, 'subscribeToChanges')) {
                                try {
                                    $webhookUrl = $row['webhook_url'];
                                    if (!$webhookUrl) {
                                        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
                                        $webhookUrl = "{$baseUrl}/bridges/webhook/{$subscriptionBridge}?tenant_id={$subscriptionTenantId}";
                                    }

                                    $newSubscriptionId = $bridgeInstance->subscribeToChanges(
                                        $row['calendar_id'],
                                        $webhookUrl,
                                        $row['subscription_id']
                                    );

                                    $recreated[] = [
                                        'success' => true,
                                        'old_subscription_id' => $row['subscription_id'],
                                        'new_subscription_id' => $newSubscriptionId,
                                        'reason' => 'not_found_on_microsoft'
                                    ];
                                } catch (\Exception $e) {
                                    $failed[] = ['subscription_id' => $row['subscription_id'], 'error' => $e->getMessage()];
                                }
                            }
                        } else {
                            $failed[] = $result;
                        }
                    }
                } else {
                    $failed[] = ['subscription_id' => $row['subscription_id'], 'error' => 'Bridge does not support renewal'];
                }
            }
        }

        return [
            'checked' => count($rows),
            'renewed' => $renewed,
            'recreated' => $recreated,
            'failed' => $failed,
            'summary' => [
                'total_checked' => count($rows),
                'renewed_count' => count($renewed),
                'recreated_count' => count($recreated),
                'failed_count' => count($failed),
                'success_count' => count($renewed) + count($recreated)
            ]
        ];
    }
}
