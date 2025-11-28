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

    public function findPendingItems(string $queueType, int $limit, ?string $tenantId = null, string $sortOrder = 'ASC'): array
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
        $sql = "UPDATE bridge_queue SET status = :status, error_message = :error_message WHERE id = :id";
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
}
