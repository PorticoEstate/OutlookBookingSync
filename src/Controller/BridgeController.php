<?php

namespace App\Controller;

use App\Services\BridgeManager;
use App\Services\SyncLogService;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeQueueRepository;
use App\Repository\BridgeSubscriptionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * BridgeController provides endpoints to interact with bridges: listing, syncing,
 * webhook handling, subscriptions, resources, diagnostics, and metrics.
 */
class BridgeController
{
    private $bridgeManager;
    private $logger;
    private $resourceRepository;
    private $mappingRepository;
    private $queueRepository;
    private $subscriptionRepository;
    private $syncOrchestrator;
    private $webhookService;
    private $syncLogService;

    /**
     * @param BridgeManager $bridgeManager Bridge orchestrator
     * @param LoggerInterface $logger Logger
     * @param BridgeResourceRepository $resourceRepository
     * @param BridgeMappingRepository $mappingRepository
     * @param BridgeQueueRepository $queueRepository
     * @param BridgeSubscriptionRepository $subscriptionRepository
     * @param \App\Services\SyncOrchestrator $syncOrchestrator
     * @param \App\Services\WebhookService $webhookService
     * @param SyncLogService $syncLogService
     */
    public function __construct(
        BridgeManager $bridgeManager, 
        LoggerInterface $logger, 
        BridgeResourceRepository $resourceRepository,
        BridgeMappingRepository $mappingRepository,
        BridgeQueueRepository $queueRepository,
        BridgeSubscriptionRepository $subscriptionRepository,
        \App\Services\SyncOrchestrator $syncOrchestrator,
        \App\Services\WebhookService $webhookService,
        SyncLogService $syncLogService
    ) {
        $this->bridgeManager = $bridgeManager;
        $this->logger = $logger;
        $this->resourceRepository = $resourceRepository;
        $this->mappingRepository = $mappingRepository;
        $this->queueRepository = $queueRepository;
        $this->subscriptionRepository = $subscriptionRepository;
        $this->syncOrchestrator = $syncOrchestrator;
        $this->webhookService = $webhookService;
        $this->syncLogService = $syncLogService;
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
            $resourceMappings = $this->resourceRepository->findActiveMappings($sourceBridge, $targetBridge, $tenantId ?: null);

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
                        
                        $results = $this->syncOrchestrator->syncBetweenBridges(
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
                                $this->resourceRepository->updateLastSyncedAt((int)$resourceMapping['id']);
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
        $queryParams = $request->getQueryParams();
        $tenantId = (string)($queryParams['tenant_id'] ?? $request->getAttribute('tenant_id') ?? '');

        try
        {
            $result = $this->webhookService->handleWebhook($bridgeName, $body, $queryParams, $tenantId);

            if (isset($result['output'])) {
                $response->getBody()->write($result['output']);
                return $response->withHeader('Content-Type', $result['content_type'] ?? 'text/plain')
                                ->withStatus($result['status_code'] ?? 200);
            }

            if (isset($result['success']) && !$result['success']) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => $result['error'] ?? 'Unknown error']));
                return $response->withStatus($result['status_code'] ?? 400)->withHeader('Content-Type', 'application/json');
            }

            // Success response
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $result['message'] ?? 'Webhook processed',
                'bridge' => $result['bridge'] ?? $bridgeName,
                'target_bridge' => $result['target_bridge'] ?? null
            ]));
            
            $response = $response->withStatus($result['status_code'] ?? 202)->withHeader('Content-Type', 'application/json');

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
                    $this->webhookService->processWebhookQueueImmediate($tenantId);
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
                    // Check for existing active subscription to avoid duplicates
                    $existing = $this->subscriptionRepository->findActiveByCalendar($bridgeName, $calendarId, $tenantId);
                    
                    if ($existing) {
                        $subscriptions[] = [
                            'calendar_id' => $calendarId,
                            'subscription_id' => $existing['subscription_id'],
                            'webhook_url' => $existing['webhook_url'],
                            'status' => 'existing',
                            'expires_at' => $existing['expires_at']
                        ];
                        continue;
                    }

                    $subscriptionId = $bridge->subscribeToChanges($calendarId, $webhookUrl);
                    $subscriptions[] = [
                        'calendar_id' => $calendarId,
                        'subscription_id' => $subscriptionId,
                        'webhook_url' => $webhookUrl,
                        'status' => 'created'
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
     * List webhook subscriptions for a bridge or all bridges.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args May include bridgeName (optional - if not provided, returns all bridges)
     * @return Response
     */
    public function listSubscriptions(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'] ?? null;
        $queryParams = $request->getQueryParams();

        try
        {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // Handle stats_only request
            if (!empty($queryParams['stats_only']))
            {
                $stats = $this->subscriptionRepository->getStats($bridgeName, $tenantId ?: null);

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'stats' => $stats
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Prepare filters
            $filters = [
                'search' => $queryParams['search'] ?? null,
                'status' => $queryParams['status'] ?? null,
                'limit' => max(1, min(200, (int)($queryParams['limit'] ?? 50))),
                'offset' => max(0, (int)($queryParams['offset'] ?? 0))
            ];

            $subscriptions = $this->subscriptionRepository->findAll($bridgeName, $tenantId ?: null, $filters);

            $response->getBody()->write(json_encode([
                'success' => true,
                'subscriptions' => $subscriptions,
                'count' => count($subscriptions),
                'limit' => $filters['limit'],
                'offset' => $filters['offset']
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to list subscriptions', ['error' => $e->getMessage()]);

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
        $subscriptionId = $args['subscriptionId'];

        try
        {
            // Fetch subscription details from database to ensure we have the correct bridge and tenant
            $subscription = $this->subscriptionRepository->findById($subscriptionId);

            if (!$subscription)
            {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Subscription not found'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $bridgeName = $subscription['bridge_type'];
            $tenantId = $subscription['tenant_id'];

            $providerUnsubscribeSuccess = false;

            // Try to unsubscribe from the provider (if bridge supports it)
            try
            {
                $bridge = $this->bridgeManager->getBridgeForTenant($tenantId ?: 'default', $bridgeName);
                if (method_exists($bridge, 'unsubscribeFromChanges'))
                {
                    $providerUnsubscribeSuccess = $bridge->unsubscribeFromChanges($subscriptionId);
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
            $deleted = $this->subscriptionRepository->delete($subscriptionId, $bridgeName, $tenantId);

            // If not deleted by the repository call (0 rows affected), it might be because 
            // the bridge's unsubscribeFromChanges() already deleted it. 
            // We should check if it's actually gone.
            if (!$deleted) {
                $exists = $this->subscriptionRepository->findById($subscriptionId);
                if (!$exists) {
                    $deleted = true;
                }
            }

            if ($deleted || $providerUnsubscribeSuccess)
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
                $this->logger,
                $this->bridgeManager,
                $this->queueRepository,
                $this->mappingRepository,
                $this->syncLogService
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
                $this->logger,
                $this->bridgeManager,
                $this->queueRepository,
                $this->mappingRepository,
                $this->syncLogService
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

            $result = $this->webhookService->processWebhookQueueBatch($batchSize, $tenantId);

            $response->getBody()->write(json_encode(array_merge([
                'success' => true,
                'message' => 'Webhook queue processed'
            ], $result)));

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
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');

            $results = [];
            
            if ($bridgeName)
            {
                // Process for specific bridge (and tenant if provided)
                $results[$bridgeName] = $this->syncOrchestrator->processPendingSyncs($bridgeName, $batchSize, ['tenant_id' => $tenantId ?: null]);
            }
            else
            {
                // Process for all bridges
                $configuredBridges = $this->bridgeManager->get_configured_bridges();
                
                foreach ($configuredBridges as $tId => $bridges)
                {
                    // If request is scoped to a specific tenant, skip others
                    if ($tenantId && $tenantId !== 'default' && $tenantId !== $tId)
                    {
                        continue;
                    }

                    foreach (array_keys($bridges) as $bName)
                    {
                        try
                        {
                            $results[$bName] = $this->syncOrchestrator->processPendingSyncs($bName, $batchSize, ['tenant_id' => $tId]);
                        }
                        catch (\Exception $e)
                        {
                            $results[$bName] = [
                                'processed' => 0,
                                'errors' => 1,
                                'error_details' => [['error' => $e->getMessage()]]
                            ];
                            
                            $this->logger->error('Failed to process pending syncs for bridge', [
                                'bridge' => $bName,
                                'tenant_id' => $tId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

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
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');

            $results = [];

            if ($bridgeName)
            {
                $count = $this->syncOrchestrator->reEnableFailedEvents($bridgeName, $eventIds, ['tenant_id' => $tenantId ?: null]);
                $results[$bridgeName] = $count;
            }
            else
            {
                $configuredBridges = $this->bridgeManager->get_configured_bridges();
                foreach ($configuredBridges as $tId => $bridges)
                {
                    if ($tenantId && $tenantId !== 'default' && $tenantId !== $tId)
                    {
                        continue;
                    }
                    
                    foreach (array_keys($bridges) as $bName)
                    {
                        try
                        {
                            $count = $this->syncOrchestrator->reEnableFailedEvents($bName, $eventIds, ['tenant_id' => $tId]);
                            $results[$bName] = $count;
                        }
                        catch (\Exception $e)
                        {
                            $results[$bName] = 0;
                            $this->logger->error('Failed to re-enable failed events for bridge', [
                                'bridge' => $bName,
                                'tenant_id' => $tId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

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
            $bridgeMapping = $this->mappingRepository->findByEventIdAndBridge($eventId, $bridgeName, $tenantId);

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
