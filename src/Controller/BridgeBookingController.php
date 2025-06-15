<?php

namespace App\Controller;

use App\Service\BridgeManager;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * BridgeBookingController - Bridge-compatible booking system integration
 * 
 * This controller replaces the legacy BookingSystemController and provides
 * booking system integration using the generic bridge pattern instead of
 * direct database table access.
 */
class BridgeBookingController
{
    private $bridgeManager;
    private $logger;
    private $db;
    
    public function __construct(BridgeManager $bridgeManager, LoggerInterface $logger, PDO $db)
    {
        $this->bridgeManager = $bridgeManager;
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * Process pending bridge sync operations (replaces processImportedEvents)
     */
    public function processPendingSyncs(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
            $sourceBridge = $queryParams['source_bridge'] ?? 'outlook';
            $targetBridge = $queryParams['target_bridge'] ?? 'booking_system';
            
            // Get pending sync operations from bridge queue
            $pendingOps = $this->getPendingBridgeOperations($limit);
            $processedCount = 0;
            $results = [];
            
            foreach ($pendingOps as $operation) {
                try {
                    $result = $this->processBridgeOperation($operation);
                    $results[] = $result;
                    $processedCount++;
                    
                    // Mark operation as completed
                    $this->markOperationCompleted($operation['id'], $result);
                    
                } catch (\Exception $e) {
                    $this->logger->error('Bridge operation failed', [
                        'operation_id' => $operation['id'],
                        'error' => $e->getMessage()
                    ]);
                    
                    $this->markOperationFailed($operation['id'], $e->getMessage());
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Successfully processed pending bridge operations',
                'results' => [
                    'processed_count' => $processedCount,
                    'total_pending' => count($pendingOps),
                    'operations' => $results
                ]
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process pending syncs', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to process pending syncs: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get bridge processing statistics (replaces getProcessingStats)
     */
    public function getBridgeStats(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $hours = isset($queryParams['hours']) ? (int)$queryParams['hours'] : 24;
            
            // Get statistics from bridge_sync_logs
            $stats = $this->calculateBridgeStats($hours);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'time_period_hours' => $hours,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get bridge stats', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get bridge statistics: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get pending bridge operations (replaces getPendingImports)
     */
    public function getPendingOperations(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
            $bridgeType = $queryParams['bridge_type'] ?? null;
            
            $pendingOps = $this->getPendingBridgeOperations($limit, $bridgeType);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'pending_operations' => $pendingOps,
                'count' => count($pendingOps),
                'limit' => $limit
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get pending operations', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get pending operations: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get completed bridge operations (replaces getProcessedImports)
     */
    public function getCompletedOperations(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
            $hours = isset($queryParams['hours']) ? (int)$queryParams['hours'] : 24;
            
            $completedOps = $this->getCompletedBridgeOperations($limit, $hours);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'completed_operations' => $completedOps,
                'count' => count($completedOps),
                'time_period_hours' => $hours
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get completed operations', ['error' => $e->getMessage()]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get completed operations: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get pending bridge operations from queue
     */
    private function getPendingBridgeOperations($limit = 50, $bridgeType = null): array
    {
        $sql = "
            SELECT id, queue_type, source_bridge, target_bridge, priority, 
                   payload, scheduled_at, attempts, created_at
            FROM bridge_queue 
            WHERE status = 'pending' 
        ";
        
        $params = [];
        
        if ($bridgeType) {
            $sql .= " AND (source_bridge = :bridge_type OR target_bridge = :bridge_type)";
            $params['bridge_type'] = $bridgeType;
        }
        
        $sql .= " ORDER BY priority ASC, scheduled_at ASC LIMIT :limit";
        $params['limit'] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Process a single bridge operation
     */
    private function processBridgeOperation($operation): array
    {
        $payload = json_decode($operation['payload'], true);
        
        switch ($operation['queue_type']) {
            case 'sync':
                return $this->processSyncOperation($operation, $payload);
                
            case 'webhook':
                return $this->processWebhookOperation($operation, $payload);
                
            case 'deletion':
                return $this->processDeletionOperation($operation, $payload);
                
            default:
                throw new \Exception("Unknown operation type: {$operation['queue_type']}");
        }
    }
    
    /**
     * Process sync operation using bridge manager
     */
    private function processSyncOperation($operation, $payload): array
    {
        $source = $operation['source_bridge'];
        $target = $operation['target_bridge'];
        
        $sourceCalendarId = $payload['source_calendar_id'] ?? '';
        $targetCalendarId = $payload['target_calendar_id'] ?? '';
        $startDate = $payload['start_date'] ?? date('Y-m-d');
        $endDate = $payload['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
        
        $results = $this->bridgeManager->syncBetweenBridges(
            $source, 
            $target, 
            $sourceCalendarId, 
            $targetCalendarId, 
            $startDate, 
            $endDate,
            ['handle_deletions' => true]
        );
        
        return [
            'operation_type' => 'sync',
            'operation_id' => $operation['id'],
            'source_bridge' => $source,
            'target_bridge' => $target,
            'sync_results' => $results
        ];
    }
    
    /**
     * Process webhook operation
     */
    private function processWebhookOperation($operation, $payload): array
    {
        // Use bridge manager to process webhook
        $bridgeController = new \App\Controller\BridgeController($this->bridgeManager, $this->logger);
        
        // Convert operation to webhook format and process
        $webhookData = [
            'source_bridge' => $operation['source_bridge'],
            'calendar_id' => $payload['calendar_id'] ?? '',
            'event_id' => $payload['event_id'] ?? '',
            'change_type' => $payload['change_type'] ?? 'updated'
        ];
        
        return [
            'operation_type' => 'webhook',
            'operation_id' => $operation['id'],
            'processed_webhook' => $webhookData
        ];
    }
    
    /**
     * Process deletion operation
     */
    private function processDeletionOperation($operation, $payload): array
    {
        $eventId = $payload['event_id'] ?? '';
        $calendarId = $payload['calendar_id'] ?? '';
        
        // Find mapping and delete from target bridge
        $mapping = $this->findBridgeMapping($eventId, $calendarId);
        
        if ($mapping) {
            $targetBridge = $this->bridgeManager->getBridge($mapping['target_bridge']);
            $targetBridge->deleteEvent($mapping['target_calendar_id'], $mapping['target_event_id']);
            
            // Remove mapping
            $this->deleteBridgeMapping($mapping['id']);
        }
        
        return [
            'operation_type' => 'deletion',
            'operation_id' => $operation['id'],
            'deleted_mapping' => $mapping ? true : false,
            'mapping_id' => $mapping['id'] ?? null
        ];
    }
    
    /**
     * Mark operation as completed
     */
    private function markOperationCompleted($operationId, $result)
    {
        $sql = "
            UPDATE bridge_queue 
            SET status = 'completed', 
                processed_at = CURRENT_TIMESTAMP,
                error_message = NULL
            WHERE id = :id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $operationId]);
    }
    
    /**
     * Mark operation as failed
     */
    private function markOperationFailed($operationId, $errorMessage)
    {
        $sql = "
            UPDATE bridge_queue 
            SET status = 'failed', 
                processed_at = CURRENT_TIMESTAMP,
                error_message = :error_message,
                attempts = attempts + 1
            WHERE id = :id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $operationId,
            'error_message' => $errorMessage
        ]);
    }
    
    /**
     * Calculate bridge statistics
     */
    private function calculateBridgeStats($hours): array
    {
        $sql = "
            SELECT 
                source_bridge,
                target_bridge,
                operation,
                status,
                COUNT(*) as operation_count,
                AVG(duration_ms) as avg_duration_ms,
                SUM(event_count) as total_events
            FROM bridge_sync_logs 
            WHERE created_at > NOW() - INTERVAL :hours HOUR
            GROUP BY source_bridge, target_bridge, operation, status
            ORDER BY operation_count DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['hours' => $hours]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get completed bridge operations
     */
    private function getCompletedBridgeOperations($limit, $hours): array
    {
        $sql = "
            SELECT * FROM bridge_queue 
            WHERE status IN ('completed', 'failed')
            AND processed_at > NOW() - INTERVAL :hours HOUR
            ORDER BY processed_at DESC 
            LIMIT :limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['hours' => $hours, 'limit' => $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find bridge mapping by event details
     */
    private function findBridgeMapping($eventId, $calendarId): ?array
    {
        $sql = "
            SELECT * FROM bridge_mappings 
            WHERE (source_event_id = :event_id AND source_calendar_id = :calendar_id)
               OR (target_event_id = :event_id AND target_calendar_id = :calendar_id)
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['event_id' => $eventId, 'calendar_id' => $calendarId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Delete bridge mapping
     */
    private function deleteBridgeMapping($mappingId)
    {
        $sql = "DELETE FROM bridge_mappings WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $mappingId]);
    }
}
