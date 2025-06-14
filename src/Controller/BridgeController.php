<?php

namespace App\Controller;

use App\Service\BridgeManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class BridgeController
{
    private $bridgeManager;
    private $logger;
    
    public function __construct(BridgeManager $bridgeManager, LoggerInterface $logger)
    {
        $this->bridgeManager = $bridgeManager;
        $this->logger = $logger;
    }
    
    /**
     * List all available bridges
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
     * Get calendars for a specific bridge
     */
    public function getCalendars(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        
        try {
            $bridge = $this->bridgeManager->getBridge($bridgeName);
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
     * Sync between two bridges
     */
    public function syncBridges(Request $request, Response $response, $args)
    {
        $sourceBridge = $args['sourceBridge'];
        $targetBridge = $args['targetBridge'];
        
        $body = json_decode($request->getBody()->getContents(), true);
        
        // Validate required parameters
        $required = ['source_calendar_id', 'target_calendar_id'];
        foreach ($required as $param) {
            if (!isset($body[$param]) || empty($body[$param])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => "Missing required parameter: {$param}"
                ]));
                
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        $sourceCalendarId = $body['source_calendar_id'];
        $targetCalendarId = $body['target_calendar_id'];
        $startDate = $body['start_date'] ?? date('Y-m-d');
        $endDate = $body['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
        
        $options = [
            'handle_deletions' => $body['handle_deletions'] ?? false,
            'skip_updates' => $body['skip_updates'] ?? false,
            'dry_run' => $body['dry_run'] ?? false
        ];
        
        try {
            $this->logger->info('Bridge sync requested', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'source_calendar' => $sourceCalendarId,
                'target_calendar' => $targetCalendarId,
                'date_range' => [$startDate, $endDate],
                'options' => $options
            ]);
            
            if ($options['dry_run']) {
                $results = $this->performDryRun($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate);
            } else {
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
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'sync_results' => $results,
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
     * Handle webhook from any bridge
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
            
            $this->logger->info('Webhook received', [
                'bridge' => $bridgeName,
                'data' => $body
            ]);
            
            // Determine the target bridge for sync
            $targetBridge = $this->determineTargetBridge($bridgeName);
            
            // Queue the sync operation using Redis or database queue
            $this->queueSyncOperation($bridgeName, $targetBridge, $body);
            
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
     * Create webhook subscriptions for a bridge
     */
    public function createSubscriptions(Request $request, Response $response, $args)
    {
        $bridgeName = $args['bridgeName'];
        $body = json_decode($request->getBody()->getContents(), true);
        
        try {
            $bridge = $this->bridgeManager->getBridge($bridgeName);
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
     * Get health status of all bridges
     */
    public function getHealthStatus(Request $request, Response $response, $args)
    {
        try {
            $bridgesInfo = $this->bridgeManager->getAllBridgesInfo();
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
     * Perform dry run sync to see what would happen
     */
    private function performDryRun($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate)
    {
        $source = $this->bridgeManager->getBridge($sourceBridge);
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
     * Determine target bridge for webhook
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
     * Queue sync operation for async processing
     */
    private function queueSyncOperation($sourceBridge, $targetBridge, $webhookData)
    {
        // Add to Redis queue if available, otherwise use database queue
        try {
            if (class_exists('Redis')) {
                $redis = new \Redis();
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
                $this->queueToDatabase($sourceBridge, $targetBridge, $webhookData);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('Redis not available, using database queue fallback', [
                'error' => $e->getMessage()
            ]);
            $this->queueToDatabase($sourceBridge, $targetBridge, $webhookData);
        }
    }
    
    /**
     * Fallback queue to database
     */
    private function queueToDatabase($sourceBridge, $targetBridge, $webhookData)
    {
        // This would require database access - for now just log
        $this->logger->info('Webhook queued for processing', [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'webhook_data' => $webhookData
        ]);
    }
    
    /**
     * Get default webhook URL for a bridge
     */
    private function getDefaultWebhookUrl($bridgeName)
    {
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
        return "{$baseUrl}/webhook/bridge/{$bridgeName}";
    }
}
