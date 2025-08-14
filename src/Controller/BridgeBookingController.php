<?php

namespace App\Controller;

use App\Services\BridgeManager;
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
/**
 * BridgeBookingController orchestrates queue processing and operations using
 * the generic BridgeManager against the booking system.
 */
class BridgeBookingController
{
    private $bridgeManager;
    private $logger;
    private $db;
    
    /**
     * @param BridgeManager $bridgeManager
     * @param LoggerInterface $logger
     * @param PDO $db
     */
    public function __construct(BridgeManager $bridgeManager, LoggerInterface $logger, PDO $db)
    {
        $this->bridgeManager = $bridgeManager;
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * Process pending bridge sync operations (replaces processImportedEvents).
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function processPendingSyncs(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
            $sourceBridge = $queryParams['source_bridge'] ?? 'outlook';
            $targetBridge = $queryParams['target_bridge'] ?? 'booking_system';
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // Get pending sync operations from bridge queue
            $pendingOps = $this->getPendingBridgeOperations($limit, null, $tenantId !== '' ? $tenantId : null);
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
     * Get pending bridge operations from queue.
     *
     * @param int $limit
     * @param string|null $bridgeType
     * @param string|null $tenantId
     * @return array
     */
    private function getPendingBridgeOperations($limit = 50, $bridgeType = null, ?string $tenantId = null): array
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
        if ($tenantId !== null) {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params['tenant_id'] = (string)$tenantId;
        }
        
        $sql .= " ORDER BY priority ASC, scheduled_at ASC LIMIT :limit";
        $params['limit'] = (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Process a single bridge operation.
     *
     * @param array $operation
     * @return array
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
     * Process sync operation using bridge manager.
     *
     * @param array $operation
     * @param array $payload
     * @return array
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
            ['handle_deletions' => true, 'tenant_id' => $payload['tenant_id'] ?? null]
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
     * Process webhook operation.
     *
     * @param array $operation
     * @param array $payload
     * @return array
     */
    private function processWebhookOperation($operation, $payload): array
    {
        // Use bridge manager to process webhook
        $bridgeController = new \App\Controller\BridgeController($this->bridgeManager, $this->logger, $this->db);
        
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
     * Process deletion operation.
     *
     * @param array $operation
     * @param array $payload
     * @return array
     */
    private function processDeletionOperation($operation, $payload): array
    {
        $eventId = $payload['event_id'] ?? '';
        $calendarId = $payload['calendar_id'] ?? '';
        $tenantId = $payload['tenant_id'] ?? null;
        
        // Find mapping and delete from target bridge
        $mapping = $this->findBridgeMapping($eventId, $calendarId, $tenantId);
        
        if ($mapping) {
            $targetBridge = $tenantId ? $this->bridgeManager->getBridgeForTenant((string)$tenantId, $mapping['target_bridge']) : $this->bridgeManager->getBridge($mapping['target_bridge']);
            $targetBridge->deleteEvent($mapping['target_calendar_id'], $mapping['target_event_id']);
            
            // Remove mapping
            $this->deleteBridgeMapping($mapping['id'], $tenantId);
        }
        
        return [
            'operation_type' => 'deletion',
            'operation_id' => $operation['id'],
            'deleted_mapping' => $mapping ? true : false,
            'mapping_id' => $mapping['id'] ?? null
        ];
    }
    
    /**
     * Mark operation as completed.
     *
     * @param int $operationId
     * @param array $result
     * @return void
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
     * Mark operation as failed.
     *
     * @param int $operationId
     * @param string $errorMessage
     * @return void
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
     * Find bridge mapping by event details.
     *
     * @param string $eventId
     * @param string $calendarId
     * @param string|null $tenantId
     * @return array|null
     */
    private function findBridgeMapping($eventId, $calendarId, ?string $tenantId = null): ?array
    {
        $sql = "
            SELECT * FROM bridge_mappings 
            WHERE (source_event_id = :event_id AND source_calendar_id = :calendar_id)
               OR (target_event_id = :event_id AND target_calendar_id = :calendar_id)
            LIMIT 1
        ";
        if ($tenantId !== null) { $sql = str_replace('LIMIT 1', ' AND (tenant_id IS NOT DISTINCT FROM :tenant_id) LIMIT 1', $sql); }
        $stmt = $this->db->prepare($sql);
        $params = ['event_id' => $eventId, 'calendar_id' => $calendarId];
        if ($tenantId !== null) { $params['tenant_id'] = (string)$tenantId; }
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Delete bridge mapping.
     *
     * @param int $mappingId
     * @param string|null $tenantId
     * @return void
     */
    private function deleteBridgeMapping($mappingId, ?string $tenantId = null)
    {
        $sql = "DELETE FROM bridge_mappings WHERE id = :id" . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
        $stmt = $this->db->prepare($sql);
        $params = ['id' => $mappingId];
        if ($tenantId !== null) { $params['tenant_id'] = (string)$tenantId; }
        $stmt->execute($params);
    }
}
