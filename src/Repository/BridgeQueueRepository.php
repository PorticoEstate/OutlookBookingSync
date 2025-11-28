<?php

namespace App\Repository;

use PDO;

class BridgeQueueRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function enqueue(string $queueType, string $sourceBridge, ?string $targetBridge, array $payload, int $priority = 5, ?string $tenantId = null): void
    {
        $sql = "INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, payload, priority, tenant_id) 
                VALUES (:queue_type, :source_bridge, :target_bridge, :payload, :priority, :tenant_id)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':queue_type' => $queueType,
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':payload' => json_encode($payload),
            ':priority' => $priority,
            ':tenant_id' => $tenantId
        ]);
    }

    /**
     * Enqueue an item only if it doesn't already exist in pending/processing state.
     * Prevents duplicate queue items for the same sync operation.
     * 
     * @param string $queueType
     * @param string $sourceBridge
     * @param string|null $targetBridge
     * @param array $payload
     * @param int $priority
     * @param string|null $tenantId
     * @return bool True if enqueued, false if duplicate exists
     */
    public function enqueueIfNotExists(
        string $queueType,
        string $sourceBridge,
        ?string $targetBridge,
        array $payload,
        int $priority = 5,
        ?string $tenantId = null
    ): bool {
        // Extract key identifiers from payload for uniqueness check
        $payloadFilter = [];
        if (isset($payload['source_calendar_id'])) {
            $payloadFilter['source_calendar_id'] = $payload['source_calendar_id'];
        }
        if (isset($payload['target_calendar_id'])) {
            $payloadFilter['target_calendar_id'] = $payload['target_calendar_id'];
        }
        if (isset($payload['event_id'])) {
            $payloadFilter['event_id'] = $payload['event_id'];
        }
        if (isset($payload['source_event_id'])) {
            $payloadFilter['source_event_id'] = $payload['source_event_id'];
        }
        if (isset($payload['resource_id'])) {
            $payloadFilter['resource_id'] = $payload['resource_id'];
        }
        
        // Check for existing pending/processing items with same criteria
        $sql = "SELECT COUNT(*) FROM bridge_queue 
                WHERE queue_type = :queue_type 
                AND source_bridge = :source_bridge 
                AND target_bridge = :target_bridge
                AND status IN ('pending', 'processing')
                AND tenant_id = :tenant_id";
        
        // Add payload-specific uniqueness check if we have filter criteria
        if (!empty($payloadFilter)) {
            $sql .= " AND payload::jsonb @> :payload_filter::jsonb";
        }
        
        $stmt = $this->db->prepare($sql);
        $params = [
            ':queue_type' => $queueType,
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':tenant_id' => $tenantId
        ];
        
        if (!empty($payloadFilter)) {
            $params[':payload_filter'] = json_encode($payloadFilter);
        }
        
        $stmt->execute($params);
        
        if ($stmt->fetchColumn() > 0) {
            return false; // Duplicate exists, skip enqueue
        }
        
        // No duplicate found, proceed with enqueue
        $this->enqueue($queueType, $sourceBridge, $targetBridge, $payload, $priority, $tenantId);
        return true;
    }

    public function findPendingItems(string $queueType = 'webhook', int $limit = 50, ?string $tenantId = null, string $sortOrder = 'ASC'): array
    {
        $sql = "
            SELECT id, tenant_id, source_bridge, target_bridge, payload, attempts, created_at
            FROM bridge_queue 
            WHERE queue_type = :queue_type 
            AND status = 'pending'
        ";
        
        $params = [':queue_type' => $queueType];
        
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        
        $sql .= " ORDER BY priority ASC, created_at " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC') . " LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        
        // Bind limit as integer for PDO compatibility with LIMIT
        $stmt->bindParam(':queue_type', $queueType);
        if ($tenantId) {
            $stmt->bindParam(':tenant_id', $tenantId);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markProcessing(int $id): void
    {
        $sql = "UPDATE bridge_queue SET status = 'processing', attempts = attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $sql = "UPDATE bridge_queue SET status = 'completed', processed_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $sql = "UPDATE bridge_queue 
                SET status = :status, 
                    error_message = :error_message, 
                    attempts = attempts + 1 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':id' => $id
        ]);
    }
    
    public function getQueueStats(?string $tenantId = null): array
    {
        $stats = [
            'webhook_queue' => ['pending_count' => 0],
            'deletion_queue' => ['pending_count' => 0],
            'processing_health' => ['health_status' => 'healthy', 'stuck_items' => []],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Count pending items
        $sql = "SELECT queue_type, COUNT(*) as count FROM bridge_queue WHERE status = 'pending'" . ($tenantId ? " AND tenant_id = :tenant_id" : "") . " GROUP BY queue_type";
        $stmt = $this->db->prepare($sql);
        if ($tenantId) {
            $stmt->bindValue(':tenant_id', $tenantId);
        }
        $stmt->execute();
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stats['webhook_queue']['pending_count'] = (int)($counts['webhook'] ?? 0);
        $stats['deletion_queue']['pending_count'] = (int)($counts['deletion'] ?? 0);

        // Check for stuck items (processing for > 10 minutes)
        // Since we don't have updated_at, we use created_at for items that are still 'processing'
        $sql = "SELECT id, queue_type, created_at FROM bridge_queue WHERE status = 'processing' AND created_at < NOW() - INTERVAL '10 minutes'" . ($tenantId ? " AND tenant_id = :tenant_id" : "");
        $stmt = $this->db->prepare($sql);
        if ($tenantId) {
            $stmt->bindValue(':tenant_id', $tenantId);
        }
        $stmt->execute();
        $stuckItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($stuckItems)) {
            $stats['processing_health']['health_status'] = 'warning';
            $stats['processing_health']['stuck_items'] = $stuckItems;
        }

        return $stats;
    }

    /**
     * Retry a failed queue item by resetting attempts and status to pending
     * 
     * @param int $id Queue item ID
     * @return bool True if item was reset, false if not found or not in failed status
     */
    public function retryFailedItem(int $id): bool
    {
        $sql = "UPDATE bridge_queue 
                SET status = 'pending', 
                    attempts = 0, 
                    error_message = NULL 
                WHERE id = :id 
                AND status = 'failed'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a queue item
     * 
     * @param int $id Queue item ID
     * @return bool True if item was deleted, false if not found
     */
    public function deleteQueueItem(int $id): bool
    {
        $sql = "DELETE FROM bridge_queue WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return $stmt->rowCount() > 0;
    }

    /**
     * Clean up old completed or failed queue items
     * 
     * @param int $daysOld Number of days to retain items (default: 30)
     * @param string|null $tenantId Optional tenant filter
     * @return int Number of items deleted
     */
    public function cleanupOldItems(int $daysOld = 30, ?string $tenantId = null): int
    {
        // Build the interval string directly (can't bind INTERVAL parameter)
        $sql = "DELETE FROM bridge_queue 
                WHERE status IN ('completed', 'failed') 
                AND created_at < NOW() - INTERVAL '" . intval($daysOld) . " days'";
        
        if ($tenantId)
        {
            $sql .= " AND tenant_id = :tenant_id";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($tenantId)
        {
            $stmt->bindValue(':tenant_id', $tenantId);
        }
        
        $stmt->execute();
        
        return $stmt->rowCount();
    }

    /**
     * Get failed queue items for manual review
     * 
     * @param string|null $tenantId Optional tenant filter
     * @param int $limit Maximum number of items to return (default: 100)
     * @return array Array of failed queue items with details
     */
    public function getFailedItems(?string $tenantId = null, int $limit = 100): array
    {
        $sql = "SELECT id, queue_type, source_bridge, target_bridge, 
                       payload, attempts, max_attempts, error_message, 
                       created_at, tenant_id 
                FROM bridge_queue 
                WHERE status = 'failed'";
        
        if ($tenantId)
        {
            $sql .= " AND tenant_id = :tenant_id";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        
        if ($tenantId)
        {
            $stmt->bindValue(':tenant_id', $tenantId);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
